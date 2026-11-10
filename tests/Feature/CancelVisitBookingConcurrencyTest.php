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

class CancelVisitBookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('executionOrders')]
    public function test_cancel_and_competing_confirmation_respect_execution_order(
        string $firstAction,
    ): void {
        $technician = User::factory()->create(['role' => 'teknisi']);

        [$cancelActor, $oldWorkOrder, $oldVisit] =
            $this->context($technician);

        $oldVisit = app(VisitSchedulingService::class)->confirm(
            $cancelActor,
            $oldVisit->id,
            [
                'command_key' => '40000000-0000-4000-8000-000000000001',
                'expected_visit_version' => 0,
                'technician_ids' => [$technician->id],
            ],
        );

        $oldBooking = $oldVisit->bookings()->sole();

        [$confirmActor, $newWorkOrder, $newVisit] =
            $this->context($technician);

        $this->assertNotSame($cancelActor->id, $confirmActor->id);
        $historyCount = OperationHistory::query()->count();
        $reason = 'Concurrency cancellation test';

        $results = $this->race(
            $technician,
            [
                'cancel' => [
                    'action' => 'cancel',
                    'actor_id' => $cancelActor->id,
                    'booking_id' => $oldBooking->id,
                    'payload' => [
                        'command_key' => '80000000-0000-4000-8000-000000000001',
                        'expected_visit_version' => 1,
                        'expected_booking_status' => 'confirmed',
                        'reason' => $reason,
                    ],
                ],
                'confirm' => [
                    'action' => 'confirm',
                    'actor_id' => $confirmActor->id,
                    'visit_id' => $newVisit->id,
                    'payload' => [
                        'command_key' => '80000000-0000-4000-8000-000000000001',
                        'expected_visit_version' => 0,
                        'technician_ids' => [$technician->id],
                    ],
                ],
            ],
            $firstAction,
        );

        $this->assertTrue(
            $results['cancel']['ok'],
            json_encode($results['cancel'], JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            $oldBooking->id,
            $results['cancel']['booking_id'],
        );

        $oldBooking->refresh();
        $oldVisit->refresh();
        $newVisit->refresh();

        $this->assertSame('cancelled', $oldBooking->status);
        $this->assertSame($reason, $oldBooking->ended_reason);
        $this->assertNull($oldBooking->actual_start_at);
        $this->assertNull($oldBooking->actual_end_at);
        $this->assertSame('cancelled', $oldVisit->status);
        $this->assertSame(2, $oldVisit->lock_version);

        $cancelFirst = $firstAction === 'cancel';

        if ($cancelFirst) {
            $this->assertTrue(
                $results['confirm']['ok'],
                json_encode($results['confirm'], JSON_THROW_ON_ERROR),
            );
            $this->assertSame('confirmed', $newVisit->status);
            $this->assertSame(1, $newVisit->lock_version);
            $this->assertSame(1, $newVisit->bookings()->count());
        } else {
            $this->assertFalse($results['confirm']['ok']);
            $this->assertSame(409, $results['confirm']['status']);
            $this->assertSame(
                ['BOOKING_TIME_CONFLICT'],
                $results['confirm']['errors']['technician_ids'],
            );
            $this->assertSame('draft', $newVisit->status);
            $this->assertSame(0, $newVisit->lock_version);
            $this->assertSame(0, $newVisit->bookings()->count());
        }

        $this->assertDatabaseCount(
            'visit_bookings',
            $cancelFirst ? 2 : 1,
        );

        $this->assertSame(
            $cancelFirst ? 1 : 0,
            VisitBooking::query()
                ->where('technician_id', $technician->id)
                ->whereIn('status', ['confirmed', 'in_progress'])
                ->count(),
        );

        $this->assertDatabaseCount(
            'operation_history',
            $historyCount + ($cancelFirst ? 2 : 1),
        );

        $history = OperationHistory::query()
            ->where('action', 'visit.booking.cancel')
            ->sole();

        $this->assertSame($oldBooking->id, $history->subject_id);
        $this->assertSame($cancelActor->id, $history->actor_id);
        $this->assertSame('assigned', $oldWorkOrder->fresh()->status);
        $this->assertSame('assigned', $newWorkOrder->fresh()->status);
        $this->assertDatabaseCount('work_order_technicians', 2);
    }

    public static function executionOrders(): array
    {
        return [
            'cancel first' => ['cancel'],
            'confirm first' => ['confirm'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $commands
     * @return array<string, array<string, mixed>>
     */
    private function race(
        User $technician,
        array $commands,
        string $firstAction,
    ): array {
        $directory = sys_get_temp_dir().'/nusalogi-cancel-'.Str::uuid();

        $this->assertTrue(mkdir($directory, 0700));
        $this->assertDatabaseCount('visit_bookings', 1);

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

            User::query()
                ->whereKey($technician->id)
                ->lockForUpdate()
                ->firstOrFail();

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
                    'barrier_table' => 'users',
                    'barrier_id' => $technician->id,
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

            $this->assertNotFalse(
                file_put_contents($goFiles[$firstAction], 'go'),
            );

            $connection->commit();

            $firstExitCode = $processes[$firstAction]->wait();

            $this->assertSame(
                0,
                $firstExitCode,
                $processes[$firstAction]->getErrorOutput(),
            );

            $secondAction = $firstAction === 'cancel'
                ? 'confirm'
                : 'cancel';

            $this->assertNotFalse(
                file_put_contents($goFiles[$secondAction], 'go'),
            );

            $results = [];

            foreach ($processes as $name => $process) {
                $exitCode = $name === $firstAction
                    ? $firstExitCode
                    : $process->wait();

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

                $this->assertSame($name, $result['action']);
                $this->assertTrue($result['barrier_reached']);
                $this->assertSame(1, $result['snapshot_booking_count']);
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
    private function context(User $technician): array
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
                'starts_at' => '2026-11-12T09:00+07:00',
                'ends_at' => '2026-11-12T11:00+07:00',
                'zone_name' => 'Asia/Jakarta',
            ],
        );

        return [$coordinator, $workOrder, $visit];
    }
}