<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use App\Services\VisitSchedulingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VisitBookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_cross_wo_booking_has_only_one_winner(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);

        [$firstCoordinator, $firstVisit] = $this->context(
            $technician,
            '2026-11-12T09:00+07:00',
            '2026-11-12T11:00+07:00',
        );

        [$secondCoordinator, $secondVisit] = $this->context(
            $technician,
            '2026-11-12T10:00+07:00',
            '2026-11-12T12:00+07:00',
        );

        $this->assertNotSame($firstCoordinator->id, $secondCoordinator->id);
        $this->assertNotSame(
            $firstVisit->work_order_id,
            $secondVisit->work_order_id,
        );

        $results = $this->race($technician, [
            [
                'actor_id' => $firstCoordinator->id,
                'visit_id' => $firstVisit->id,
            ],
            [
                'actor_id' => $secondCoordinator->id,
                'visit_id' => $secondVisit->id,
            ],
        ]);

        $successes = array_values(array_filter(
            $results,
            fn (array $result): bool => $result['ok'],
        ));

        $failures = array_values(array_filter(
            $results,
            fn (array $result): bool => ! $result['ok'],
        ));

        $this->assertCount(1, $successes);
        $this->assertCount(1, $failures);
        $this->assertSame(409, $failures[0]['status']);
        $this->assertSame(
            ['BOOKING_TIME_CONFLICT'],
            $failures[0]['errors']['technician_ids'],
        );

        $winner = WorkVisit::query()->findOrFail(
            $successes[0]['visit_id'],
        );

        $loserId = $winner->id === $firstVisit->id
            ? $secondVisit->id
            : $firstVisit->id;

        $loser = WorkVisit::query()->findOrFail($loserId);

        $this->assertSame('confirmed', $winner->status);
        $this->assertSame(1, $winner->lock_version);
        $this->assertSame('draft', $loser->status);
        $this->assertSame(0, $loser->lock_version);
        $this->assertSame(0, $loser->bookings()->count());

        $this->assertDatabaseCount('work_visits', 2);
        $this->assertDatabaseCount('visit_bookings', 1);

        $booking = VisitBooking::query()->sole();

        $this->assertSame($winner->id, $booking->visit_id);
        $this->assertSame($technician->id, $booking->technician_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertNull($booking->actual_start_at);
        $this->assertNull($booking->actual_end_at);

        $history = OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->sole();

        $this->assertSame($winner->id, $history->subject_id);
        $this->assertSame($winner->created_by, $history->actor_id);
        $this->assertDatabaseCount('work_order_technicians', 2);
    }

    /**
     *@param list<array{actor_id: int, visit_id: int, technician_ids?: list<int>}> $commands
     * @return list<array<string, mixed>>
     */
    private function race(User $technician, array $commands): array
    {
        $directory = sys_get_temp_dir().'/nusalogi-booking-'.Str::uuid();

        $this->assertTrue(mkdir($directory, 0700));
        $this->assertDatabaseCount('visit_bookings', 0);

        $mysql = config('database.connections.mysql');

        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'testing',
            'DB_URL' => '',
            'DB_HOST' => (string) $mysql['host'],
            'DB_PORT' => (string) $mysql['port'],
            'DB_USERNAME' => (string) $mysql['username'],
            'DB_PASSWORD' => (string) $mysql['password'],
            'DB_SOCKET' => (string) $mysql['unix_socket'],
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];

        $connection = DB::connection();

        $this->assertSame(0, $connection->transactionLevel());

        $parentConnectionId = (int) $connection
            ->selectOne('SELECT CONNECTION_ID() AS id')
            ->id;

        $processes = [];
        $readyFiles = [];

        try {
            // Hold the shared technician mutex until both workers reach it.
            $connection->beginTransaction();

            User::query()
                ->whereKey($technician->id)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($commands as $index => $command) {
                $readyPath = $directory.'/worker-'.$index.'.ready';
                $readyFiles[] = $readyPath;

                $process = new Process(
                    [
                        PHP_BINARY,
                        base_path('tests/Fixtures/m1-visit-booking-worker.php'),
                    ],
                    base_path(),
                    $environment,
                );

                $process->setInput(json_encode([
                    ...$command,
                    'technician_id' => $technician->id,
                    'barrier_table' => 'users',
                    'barrier_id' => $technician->id,
                    'ready_path' => $readyPath,
                    'payload' => [
                        'command_key' => '80000000-0000-4000-8000-000000000001',
                        'expected_visit_version' => 0,
                        'technician_ids' => $command['technician_ids'] ?? [$technician->id],
                    ],
                ], JSON_THROW_ON_ERROR));

                $process->setTimeout(30);
                $process->start();

                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;

            foreach ($readyFiles as $index => $readyPath) {
                while (true) {
                    clearstatcache(true, $readyPath);

                    if (is_file($readyPath)) {
                        break;
                    }

                    if (! $processes[$index]->isRunning()) {
                        $this->fail(
                            'Worker exited before the barrier: '
                            .$processes[$index]->getErrorOutput()
                            .$processes[$index]->getOutput()
                        );
                    }

                    if (microtime(true) >= $deadline) {
                        $this->fail(
                            'Both workers must reach the mutex within 15 seconds.'
                        );
                    }

                    usleep(10_000);
                }

                $this->assertTrue($processes[$index]->isRunning());
            }

            $connection->commit();

            $results = [];

            foreach ($processes as $process) {
                $exitCode = $process->wait();

                $this->assertSame(
                    0,
                    $exitCode,
                    $process->getErrorOutput(),
                );

                $result = json_decode(
                    $process->getOutput(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                $this->assertTrue($result['barrier_reached']);
                $this->assertSame(0, $result['snapshot_booking_count']);
                $this->assertSame(
                    'REPEATABLE-READ',
                    $result['isolation_level'],
                );
                $this->assertNotSame(
                    $parentConnectionId,
                    $result['connection_id'],
                );

                $results[] = $result;
            }

            $this->assertCount(
                2,
                array_unique(array_column($results, 'connection_id')),
            );

            return $results;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }

            rmdir($directory);
        }
    }

    /** @return array{User, WorkVisit} */
    private function context(
        User $technician,
        string $startsAt,
        string $endsAt,
    ): array {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $customer = Customer::query()->create([
            'name' => 'PT Concurrent Booking Testing',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Office',
            'address' => 'Bekasi',
        ]);

        // Setup keys bracket the confirmation key within each actor's
        // own idempotency-index gap, avoiding an unrelated shared gap.
        $workOrder = app(CreateWorkOrderService::class)->create(
            $coordinator,
            [
                'command_key' => '10000000-0000-4000-8000-000000000001',
                'origin_kind' => 'manual',
                'manual_reason' => 'Concurrency test fixture',
                'initial_scope' => [
                    'description' => 'Restore camera feed',
                    'acceptance_criteria' => 'Live feed works',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Camera inspection',
                'description' => 'Camera offline',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ],
        );

        $workOrder->technicians()->attach($technician->id, [
            'assigned_at' => now(),
        ]);

        $workOrder->forceFill(['status' => 'assigned'])->save();

        $visit = app(VisitSchedulingService::class)->createDraft(
            $coordinator,
            $workOrder->id,
            [
                'command_key' => 'f0000000-0000-4000-8000-000000000001',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'zone_name' => 'Asia/Jakarta',
            ],
        );

        return [$coordinator, $visit];
    }

    public function test_concurrent_adjacent_visits_can_both_be_confirmed(): void
{
    $technician = User::factory()->create(['role' => 'teknisi']);

    [$firstCoordinator, $firstVisit] = $this->context(
        $technician,
        '2026-11-12T09:00+07:00',
        '2026-11-12T11:00+07:00',
    );

    [$secondCoordinator, $secondVisit] = $this->context(
        $technician,
        '2026-11-12T11:00+07:00',
        '2026-11-12T13:00+07:00',
    );

    $results = $this->race($technician, [
        [
            'actor_id' => $firstCoordinator->id,
            'visit_id' => $firstVisit->id,
        ],
        [
            'actor_id' => $secondCoordinator->id,
            'visit_id' => $secondVisit->id,
        ],
    ]);

    foreach ($results as $result) {
        $this->assertTrue(
            $result['ok'],
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        $this->assertSame(1, $result['lock_version']);
    }

    $this->assertNotSame(
        $results[0]['visit_id'],
        $results[1]['visit_id'],
    );

    $this->assertSame('confirmed', $firstVisit->fresh()->status);
    $this->assertSame('confirmed', $secondVisit->fresh()->status);

    $this->assertSame(
        $firstVisit->fresh()->ends_at->getTimestamp(),
        $secondVisit->fresh()->starts_at->getTimestamp(),
    );

    $this->assertDatabaseCount('visit_bookings', 2);
    $this->assertSame(
        2,
        OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->count(),
    );
}

public function test_concurrent_groups_with_reversed_input_have_one_atomic_winner(): void
{
    $firstTechnician = User::factory()->create(['role' => 'teknisi']);
    $secondTechnician = User::factory()->create(['role' => 'teknisi']);

    [$firstCoordinator, $firstVisit] = $this->context(
        $firstTechnician,
        '2026-11-12T09:00+07:00',
        '2026-11-12T11:00+07:00',
    );

    [$secondCoordinator, $secondVisit] = $this->context(
        $firstTechnician,
        '2026-11-12T10:00+07:00',
        '2026-11-12T12:00+07:00',
    );

    foreach ([$firstVisit, $secondVisit] as $visit) {
        $visit->workOrder->technicians()->attach($secondTechnician->id, [
            'assigned_at' => now(),
        ]);
    }

    $ids = [$firstTechnician->id, $secondTechnician->id];

    $results = $this->race($firstTechnician, [
        [
            'actor_id' => $firstCoordinator->id,
            'visit_id' => $firstVisit->id,
            'technician_ids' => $ids,
        ],
        [
            'actor_id' => $secondCoordinator->id,
            'visit_id' => $secondVisit->id,
            'technician_ids' => array_reverse($ids),
        ],
    ]);

    $successes = array_values(array_filter(
        $results,
        fn (array $result): bool => $result['ok'],
    ));

    $failures = array_values(array_filter(
        $results,
        fn (array $result): bool => ! $result['ok'],
    ));

    $this->assertCount(1, $successes);
    $this->assertCount(1, $failures);
    $this->assertSame(409, $failures[0]['status']);
    $this->assertSame(
        ['BOOKING_TIME_CONFLICT'],
        $failures[0]['errors']['technician_ids'],
    );

    $winner = WorkVisit::query()->findOrFail(
        $successes[0]['visit_id'],
    );

    $loserId = $winner->id === $firstVisit->id
        ? $secondVisit->id
        : $firstVisit->id;

    $loser = WorkVisit::query()->findOrFail($loserId);

    $this->assertSame('confirmed', $winner->status);
    $this->assertSame(1, $winner->lock_version);
    $this->assertSame(
        $ids,
        $winner->bookings()
            ->orderBy('technician_id')
            ->pluck('technician_id')
            ->all(),
    );

    $this->assertSame('draft', $loser->status);
    $this->assertSame(0, $loser->lock_version);
    $this->assertSame(0, $loser->bookings()->count());

    $this->assertDatabaseCount('visit_bookings', 2);
    $this->assertDatabaseCount('work_order_technicians', 4);
    $this->assertSame(
        1,
        OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->count(),
    );
}
}