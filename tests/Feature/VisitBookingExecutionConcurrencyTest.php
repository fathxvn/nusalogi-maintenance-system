<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use App\Services\VisitBookingExecutionService;
use App\Services\VisitSchedulingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VisitBookingExecutionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_technician_cannot_start_two_work_orders_concurrently(): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        [$actor, $firstWo, $first] = $this->context($tech);
        [$other, $secondWo, $second] = $this->context($tech, '2026-11-12T12:00+07:00', '2026-11-12T14:00+07:00');
        $this->confirm($actor, $first, $tech);
        $this->confirm($other, $second, $tech);
        $historyCount = OperationHistory::query()->count();
        $versions = [$firstWo->id => $firstWo->fresh()->lock_version, $secondWo->id => $secondWo->fresh()->lock_version];

        $results = $this->race($tech, [
            'first' => $this->startCommand($tech, $first),
            'second' => $this->startCommand($tech, $second),
        ]);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        foreach (['first' => $first, 'second' => $second] as $name => $visit) {
            $won = $results[$name]['ok'];
            $booking = $visit->bookings()->sole();
            $wo = $visit->workOrder;
            $this->assertSame($won ? 'in_progress' : 'confirmed', $booking->status);
            $this->assertSame($won ? 'in_progress' : 'confirmed', $visit->fresh()->status);
            $this->assertSame($won ? 2 : 1, $visit->fresh()->lock_version);
            $this->assertSame($won ? 'in_progress' : 'assigned', $wo->status);
            $this->assertSame($versions[$wo->id] + ($won ? 1 : 0), $wo->lock_version);
            $this->assertNull($booking->actual_end_at);
            if ($won) {
                $this->assertNotNull($booking->actual_start_at);
            } else {
                $this->assertSame(409, $results[$name]['status']);
                $this->assertSame(['TECHNICIAN_ALREADY_WORKING'], $results[$name]['errors']['booking_id']);
                $this->assertNull($booking->actual_start_at);
                $this->assertSame(0, $wo->updates()->where('to_status', 'in_progress')->count());
            }
        }
        $this->assertSame(1, VisitBooking::query()->where('status', 'in_progress')->count());
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.booking.start')->count());
        $this->assertDatabaseCount('operation_history', $historyCount + 1);
    }

    public function test_concurrent_identical_start_replays_one_mutation_and_actual_time(): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        [$actor, $wo, $visit] = $this->context($tech);
        $this->confirm($actor, $visit, $tech);
        $command = $this->startCommand($tech, $visit);
        $historyCount = OperationHistory::query()->count();
        $version = $wo->fresh()->lock_version;

        $results = $this->race($tech, ['first' => $command, 'second' => $command], 'work_orders', $wo->id);

        $this->assertTrue($results['first']['ok']);
        $this->assertTrue($results['second']['ok']);
        $this->assertSame($results['first']['booking_id'], $results['second']['booking_id']);
        $this->assertNotNull($results['first']['actual_start_at']);
        $this->assertSame($results['first']['actual_start_at'], $results['second']['actual_start_at']);
        $this->assertSame('in_progress', $visit->fresh()->status);
        $this->assertSame(2, $visit->fresh()->lock_version);
        $this->assertSame($version + 1, $wo->fresh()->lock_version);
        $this->assertSame(1, $wo->updates()->where('to_status', 'in_progress')->count());
        $history = OperationHistory::query()->where('action', 'visit.booking.start')->sole();
        $this->assertSame($results['first']['actual_start_at'], $history->facts['actual_start_at']);
        $this->assertDatabaseCount('operation_history', $historyCount + 1);
    }

    public function test_participants_race_on_observed_version_then_finish_visit_only_after_last_completion(): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        $otherTech = User::factory()->create(['role' => 'teknisi']);
        [$actor, $wo, $visit] = $this->context($tech);
        $wo->technicians()->attach($otherTech, ['assigned_at' => now()]);
        app(VisitSchedulingService::class)->confirm($actor, $visit->id, [
            'command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$tech->id, $otherTech->id],
        ]);

        $results = $this->race($tech, [
            'first' => $this->startCommand($tech, $visit),
            'second' => $this->startCommand($otherTech, $visit),
        ], 'work_orders', $wo->id);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        $loserName = $results['first']['ok'] ? 'second' : 'first';
        $loser = $loserName === 'second' ? $otherTech : $tech;
        $this->assertSame(409, $results[$loserName]['status']);
        $this->assertSame(['VISIT_VERSION_CONFLICT'], $results[$loserName]['errors']['expected_visit_version']);
        $this->assertSame(2, $visit->fresh()->lock_version);
        $this->assertSame(1, $visit->bookings()->where('status', 'in_progress')->count());
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.booking.start')->count());

        $service = app(VisitBookingExecutionService::class);
        $retry = $this->startCommand($loser, $visit, 2);
        $service->start($loser, $retry['booking_id'], $retry['payload']);
        $this->assertSame(3, $visit->fresh()->lock_version);
        $this->assertSame(2, $visit->bookings()->where('status', 'in_progress')->count());
        $this->assertSame(2, OperationHistory::query()->where('action', 'visit.booking.start')->count());

        foreach ([$tech, $otherTech] as $index => $participant) {
            $booking = $visit->bookings()->where('technician_id', $participant->id)->sole();
            $service->complete($participant, $booking->id, [
                'command_key' => (string) Str::uuid(), 'expected_visit_version' => 3 + $index, 'expected_booking_status' => 'in_progress',
            ]);
            $this->assertSame($index === 0 ? 'in_progress' : 'completed', $visit->fresh()->status);
            $this->assertSame(4 + $index, $visit->fresh()->lock_version);
            $this->assertSame('completed', $booking->fresh()->status);
            $this->assertNotNull($booking->fresh()->actual_end_at);
        }
        $this->assertSame('in_progress', $wo->fresh()->status);
        $this->assertNull($wo->fresh()->completed_at);
        $this->assertSame(2, OperationHistory::query()->where('action', 'visit.booking.complete')->count());
    }

    #[DataProvider('coordinatorActions')]
    public function test_execution_racing_coordinator_changes_has_one_atomic_winner(string $action): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        [$actor, $wo, $visit] = $this->context($tech);
        $this->confirm($actor, $visit, $tech);
        $booking = $visit->bookings()->sole();
        $historyCount = OperationHistory::query()->count();
        $command = ['action' => $action, 'actor_id' => $actor->id, 'booking_id' => $booking->id, 'visit_id' => $visit->id,
            'payload' => ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 1,
                'expected_booking_status' => 'confirmed', 'reason' => 'Execution race',
                'starts_at' => '2026-11-12T12:00+07:00', 'ends_at' => '2026-11-12T14:00+07:00',
                'zone_name' => 'Asia/Jakarta', 'technician_ids' => [$tech->id]]];

        $results = $this->race($tech, ['start' => $this->startCommand($tech, $visit), $action => $command], 'work_orders', $wo->id);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        $loser = $results['start']['ok'] ? $action : 'start';
        $this->assertSame(409, $results[$loser]['status']);
        $this->assertSame(['VISIT_VERSION_CONFLICT'], $results[$loser]['errors']['expected_visit_version']);
        $this->assertSame(2, $visit->fresh()->lock_version);
        if ($results['start']['ok']) {
            $this->assertSame('in_progress', $booking->fresh()->status);
            $this->assertNotNull($booking->fresh()->actual_start_at);
            $this->assertSame('in_progress', $visit->fresh()->status);
            $this->assertSame('in_progress', $wo->fresh()->status);
            $this->assertSame(0, $visit->successorVisit()->count());
        } else {
            $this->assertSame($action === 'cancel' ? 'cancelled' : 'superseded', $visit->fresh()->status);
            $this->assertSame('cancelled', $booking->fresh()->status);
            $this->assertNull($booking->fresh()->actual_start_at);
            $this->assertSame('assigned', $wo->fresh()->status);
            $this->assertSame($action === 'reschedule' ? 1 : 0, $visit->successorVisit()->count());
        }
        $this->assertNull($booking->fresh()->actual_end_at);
        $this->assertDatabaseCount('operation_history', $historyCount + 1);
        $this->assertSame(1, $wo->technicians()->count());
    }

    public static function coordinatorActions(): array
    {
        return ['cancel' => ['cancel'], 'reschedule' => ['reschedule']];
    }

    private function confirm(User $actor, WorkVisit $visit, User $tech): void
    {
        app(VisitSchedulingService::class)->confirm($actor, $visit->id, [
            'command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$tech->id],
        ]);
    }

    /** @return array<string, mixed> */
    private function startCommand(User $tech, WorkVisit $visit, int $version = 1): array
    {
        return ['action' => 'start', 'actor_id' => $tech->id,
            'booking_id' => $visit->bookings()->where('technician_id', $tech->id)->sole()->id,
            'payload' => ['command_key' => (string) Str::uuid(), 'expected_visit_version' => $version, 'expected_booking_status' => 'confirmed']];
    }

    /**
     * @param  array<string, array<string, mixed>>  $commands
     * @return array<string, array<string, mixed>>
     */
    private function race(
        User $technician,
        array $commands,
        string $barrierTable = 'users',
        ?int $barrierId = null,
    ): array {
        $directory = sys_get_temp_dir().'/nusalogi-execution-'.Str::uuid();

        $this->assertTrue(mkdir($directory, 0700));
        $snapshotCount = VisitBooking::query()->where('technician_id', $technician->id)->count();

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
        $goFiles = [];

        try {
            $connection->beginTransaction();

            DB::table($barrierTable)->where('id', $barrierId ?? $technician->id)->lockForUpdate()->first();

            foreach ($commands as $name => $command) {
                $readyFiles[$name] = $directory.'/'.$name.'.ready';
                $goFiles[$name] = $directory.'/'.$name.'.go';

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
                    'barrier_table' => $barrierTable,
                    'barrier_id' => $barrierId ?? $technician->id,
                    'ready_path' => $readyFiles[$name],
                    'go_path' => $goFiles[$name],
                    'probe_lock' => true,
                ], JSON_THROW_ON_ERROR));

                $process->setTimeout(40);
                $process->start();

                $processes[$name] = $process;
            }

            $deadline = microtime(true) + 15;

            foreach ($readyFiles as $name => $readyPath) {
                while (true) {
                    clearstatcache(true, $readyPath);

                    if (is_file($readyPath)) {
                        break;
                    }

                    if (! $processes[$name]->isRunning()) {
                        $this->fail(
                            'Worker exited before the barrier: '
                            .$processes[$name]->getErrorOutput()
                            .$processes[$name]->getOutput()
                        );
                    }

                    if (microtime(true) >= $deadline) {
                        $this->fail(
                            'Both workers must reach the barrier within 15 seconds.'
                        );
                    }

                    usleep(10_000);
                }

                $this->assertTrue($processes[$name]->isRunning());
            }

            foreach ($commands as $name => $command) {
                $ready = json_decode(file_get_contents($readyFiles[$name]), true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($ready['lock_contended']);
                $this->assertNotFalse(file_put_contents($goFiles[$name], 'go'));
            }
            $connection->commit();

            $results = [];

            foreach ($processes as $name => $process) {
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

                $this->assertSame($commands[$name]['action'], $result['action']);
                $this->assertTrue($result['barrier_reached']);
                $this->assertTrue($result['lock_contended']);
                $this->assertSame($snapshotCount, $result['snapshot_booking_count']);
                $this->assertSame(
                    'REPEATABLE-READ',
                    $result['isolation_level'],
                );
                $this->assertNotSame(
                    $parentConnectionId,
                    $result['connection_id'],
                );

                $results[$name] = $result;
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

    /** @return array{User, WorkOrder, WorkVisit} */
    private function context(User $technician, string $starts = '2026-11-12T09:00+07:00', string $ends = '2026-11-12T11:00+07:00'): array
    {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $customer = Customer::query()->create([
            'name' => 'PT Execution Race Testing',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Office',
            'address' => 'Bekasi',
        ]);

        // Bracket each actor's command keys to avoid an unrelated
        // shared idempotency-index gap during this resource-lock test.
        $workOrder = app(CreateWorkOrderService::class)->create(
            $coordinator,
            [
                'command_key' => '10000000-0000-4000-8000-000000000001',
                'origin_kind' => 'manual',
                'manual_reason' => 'Execution concurrency fixture',
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
                'starts_at' => $starts,
                'ends_at' => $ends,
                'zone_name' => 'Asia/Jakarta',
            ],
        );

        return [$coordinator, $workOrder, $visit];
    }
}
