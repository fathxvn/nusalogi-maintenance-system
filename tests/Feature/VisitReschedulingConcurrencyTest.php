<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use App\Services\VisitSchedulingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VisitReschedulingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('sameSourceCommands')]
    public function test_competing_reschedules_have_one_successor_and_identical_commands_replay(bool $identical): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        [$actor, $wo, $source] = $this->context($tech);
        $this->confirm($actor, $source, $tech);
        $otherActor = $identical ? $actor : User::factory()->create(['role' => 'koordinator']);
        $command = $this->command($actor, $source, $tech);
        $results = $this->race($tech, [
            'first' => $command,
            'second' => [...$command, 'actor_id' => $otherActor->id],
        ], barrierTable: 'work_orders', barrierId: $wo->id);

        $this->assertCount($identical ? 2 : 1, array_filter($results, fn (array $result): bool => $result['ok']));
        if ($identical) {
            $this->assertSame($results['first']['visit_id'], $results['second']['visit_id']);
        } else {
            $loser = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']))[0];
            $this->assertSame(409, $loser['status']);
            $this->assertSame(['VISIT_VERSION_CONFLICT'], $loser['errors']['expected_visit_version']);
        }
        $this->assertSame('superseded', $source->fresh()->status);
        $this->assertSame(1, $source->successorVisit()->count());
        $this->assertSame(1, VisitBooking::query()->where('status', 'confirmed')->count());
        $this->assertDatabaseCount('visit_bookings', 2);
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.reschedule')->count());
        $this->assertSame('assigned', $wo->fresh()->status);
    }

    public static function sameSourceCommands(): array
    {
        return ['different actors' => [false], 'identical replay' => [true]];
    }

    public function test_cross_wo_replacements_compete_for_one_slot_without_releasing_the_losers_old_slot(): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        [$firstActor, , $first] = $this->context($tech);
        [$secondActor, , $second] = $this->context($tech, '2026-11-12T15:00+07:00', '2026-11-12T17:00+07:00');
        $this->confirm($firstActor, $first, $tech);
        $this->confirm($secondActor, $second, $tech);
        $results = $this->race($tech, [
            'first' => $this->command($firstActor, $first, $tech),
            'second' => $this->command($secondActor, $second, $tech),
        ]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        foreach (['first' => $first, 'second' => $second] as $name => $source) {
            $this->assertSame($results[$name]['ok'] ? 'superseded' : 'confirmed', $source->fresh()->status);
            $this->assertSame($results[$name]['ok'] ? 1 : 0, $source->successorVisit()->count());
            if (! $results[$name]['ok']) {
                $this->assertSame(['BOOKING_TIME_CONFLICT'], $results[$name]['errors']['technician_ids']);
                $this->assertSame('confirmed', $source->bookings()->sole()->status);
            }
        }
        $this->assertSame(2, VisitBooking::query()->where('status', 'confirmed')->count());
        $this->assertDatabaseCount('visit_bookings', 3);
    }

    #[DataProvider('commandOrders')]
    public function test_reschedule_and_confirmation_observe_current_reservations_after_waiting(string $first): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        [$actor, , $source] = $this->context($tech);
        [$otherActor, , $draft] = $this->context($tech, '2026-11-12T12:00+07:00', '2026-11-12T14:00+07:00');
        $this->confirm($actor, $source, $tech);
        $results = $this->race($tech, [
            'reschedule' => $this->command($actor, $source, $tech),
            'confirm' => ['action' => 'confirm', 'actor_id' => $otherActor->id, 'visit_id' => $draft->id,
                'payload' => ['command_key' => '80000000-0000-4000-8000-000000000001', 'expected_visit_version' => 0, 'technician_ids' => [$tech->id]]],
        ], $first);
        $loser = $first === 'confirm' ? 'reschedule' : 'confirm';
        $this->assertTrue($results[$first]['ok']);
        $this->assertFalse($results[$loser]['ok']);
        $this->assertSame(['BOOKING_TIME_CONFLICT'], $results[$loser]['errors']['technician_ids']);
        $this->assertSame($first === 'reschedule' ? 'superseded' : 'confirmed', $source->fresh()->status);
        $this->assertSame($first === 'confirm' ? 'confirmed' : 'draft', $draft->fresh()->status);
        $this->assertSame($first === 'reschedule' ? 1 : 0, $source->successorVisit()->count());
    }

    public static function commandOrders(): array
    {
        return ['reschedule first' => ['reschedule'], 'confirmation first' => ['confirm']];
    }

    #[DataProvider('cancelOrders')]
    public function test_cancel_and_reschedule_on_the_same_source_have_one_atomic_winner(string $first): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        [$actor, $wo, $source] = $this->context($tech);
        $this->confirm($actor, $source, $tech);
        $otherActor = User::factory()->create(['role' => 'koordinator']);
        $results = $this->race($tech, [
            'reschedule' => $this->command($actor, $source, $tech),
            'cancel' => ['action' => 'cancel', 'actor_id' => $otherActor->id, 'booking_id' => $source->bookings()->sole()->id,
                'payload' => ['command_key' => '80000000-0000-4000-8000-000000000001', 'expected_visit_version' => 1, 'expected_booking_status' => 'confirmed', 'reason' => 'Cancelled by coordinator']],
        ], $first, 'work_orders', $wo->id);
        $loser = $first === 'cancel' ? 'reschedule' : 'cancel';
        $this->assertTrue($results[$first]['ok']);
        $this->assertFalse($results[$loser]['ok']);
        $this->assertSame(['VISIT_VERSION_CONFLICT'], $results[$loser]['errors']['expected_visit_version']);
        $this->assertSame($first === 'cancel' ? 'cancelled' : 'superseded', $source->fresh()->status);
        $this->assertSame($first === 'reschedule' ? 1 : 0, $source->successorVisit()->count());
        $this->assertSame('assigned', $wo->fresh()->status);
        $this->assertDatabaseCount('work_order_technicians', 1);
    }

    public static function cancelOrders(): array
    {
        return ['cancel first' => ['cancel'], 'reschedule first' => ['reschedule']];
    }

    private function confirm(User $actor, WorkVisit $source, User $technician): void
    {
        app(VisitSchedulingService::class)->confirm($actor, $source->id, [
            'command_key' => '40000000-0000-4000-8000-000000000001', 'expected_visit_version' => 0, 'technician_ids' => [$technician->id],
        ]);
    }

    private function command(User $actor, WorkVisit $source, User $technician): array
    {
        return ['action' => 'reschedule', 'actor_id' => $actor->id, 'visit_id' => $source->id, 'payload' => [
            'command_key' => '80000000-0000-4000-8000-000000000001', 'expected_visit_version' => 1,
            'starts_at' => '2026-11-12T12:00+07:00', 'ends_at' => '2026-11-12T14:00+07:00',
            'zone_name' => 'Asia/Jakarta', 'technician_ids' => [$technician->id], 'reason' => 'Reschedule concurrency fixture',
        ]];
    }

    /**
     * @param  array<string, array<string, mixed>>  $commands
     * @return array<string, array<string, mixed>>
     */
    private function race(
        User $technician,
        array $commands,
        ?string $firstAction = null,
        string $barrierTable = 'users',
        ?int $barrierId = null,
    ): array {
        $directory = sys_get_temp_dir().'/nusalogi-cancel-'.Str::uuid();

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

            $release = $firstAction === null ? array_keys($commands) : [$firstAction];
            foreach ($release as $name) {
                $this->assertNotFalse(file_put_contents($goFiles[$name], 'go'));
            }
            $connection->commit();
            if ($firstAction !== null) {
                $this->assertSame(0, $processes[$firstAction]->wait(), $processes[$firstAction]->getErrorOutput());
                foreach (array_diff(array_keys($commands), [$firstAction]) as $name) {
                    $this->assertNotFalse(file_put_contents($goFiles[$name], 'go'));
                }
            }

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
            'name' => 'PT Cancellation Race Testing',
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
                'manual_reason' => 'Cancellation concurrency fixture',
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
