<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use App\Services\ServiceReportDraftService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServiceReportDraftConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('operations')]
    public function test_competing_commands_have_one_atomic_winner(string $operation): void
    {
        [$actor, $wo, $scope] = $this->context();
        $report = $operation === 'update' ? app(ServiceReportDraftService::class)->createDraft($actor, $wo->id, $scope->id, [
            'command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
        ]) : null;
        $first = $this->command($operation, $actor, $wo, $scope, $report);
        $second = $this->command($operation, $actor, $wo, $scope, $report);
        $second['payload']['diagnosis'] = 'Second contender';
        $historyCount = OperationHistory::query()->count();

        $results = $this->race($wo, ['first' => $first, 'second' => $second]);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        $loser = $results['first']['ok'] ? 'second' : 'first';
        $winner = $loser === 'first' ? 'second' : 'first';
        $this->assertSame(409, $results[$loser]['status']);
        $this->assertSame($operation === 'create' ? ['REPORT_ALREADY_EXISTS'] : ['REPORT_VERSION_CONFLICT'],
            $results[$loser]['errors'][$operation === 'create' ? 'scope_item_id' : 'expected_report_version']);
        $this->assertDatabaseCount('service_reports', 1);
        $saved = ServiceReport::query()->sole();
        $this->assertSame($winner === 'first' ? 'First contender' : 'Second contender', $saved->diagnosis);
        $this->assertSame($operation === 'create' ? 0 : 1, $saved->lock_version);
        $this->assertDatabaseCount('operation_history', $historyCount + 1);
        $this->assertSame('in_progress', $wo->fresh()->status);
    }

    #[DataProvider('operations')]
    public function test_concurrent_identical_retries_commit_one_write(string $operation): void
    {
        [$actor, $wo, $scope] = $this->context();
        $report = $operation === 'update' ? app(ServiceReportDraftService::class)->createDraft($actor, $wo->id, $scope->id, [
            'command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
        ]) : null;
        $command = $this->command($operation, $actor, $wo, $scope, $report);
        $before = OperationHistory::query()->count();

        $results = $this->race($wo, ['first' => $command, 'second' => $command]);

        $this->assertTrue($results['first']['ok']);
        $this->assertTrue($results['second']['ok']);
        $this->assertSame($results['first']['report_id'], $results['second']['report_id']);
        $this->assertSame($operation === 'create' ? 0 : 1, ServiceReport::query()->sole()->lock_version);
        $this->assertDatabaseCount('service_reports', 1);
        $this->assertDatabaseCount('operation_history', $before + 1);
    }

    public static function operations(): array
    {
        return ['create' => ['create'], 'update' => ['update']];
    }

    private function command(string $operation, User $actor, WorkOrder $wo, WorkOrderScopeItem $scope, ?ServiceReport $report): array
    {
        return [
            'action' => $operation, 'actor_id' => $actor->id, 'scope_item_id' => $scope->id,
            'report_id' => $report?->id,
            'payload' => [
                'command_key' => (string) Str::uuid(), 'diagnosis' => 'First contender',
                $operation === 'create' ? 'expected_work_order_version' : 'expected_report_version' => $report?->lock_version ?? (int) $wo->lock_version,
            ],
        ];
    }

    private function race(
        WorkOrder $workOrder,
        array $commands,
        string $barrierTable = 'work_orders',
        ?int $barrierId = null,
    ): array {
        $directory = sys_get_temp_dir().'/nusalogi-report-'.Str::uuid();

        $this->assertTrue(mkdir($directory, 0700));
        $snapshotCount = ServiceReport::query()->where('work_order_id', $workOrder->id)->count();

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

            DB::table($barrierTable)->where('id', $barrierId ?? $workOrder->id)->lockForUpdate()->first();

            foreach ($commands as $name => $command) {
                $readyFiles[$name] = $directory.'/'.$name.'.ready';
                $goFiles[$name] = $directory.'/'.$name.'.go';

                $process = new Process(
                    [
                        PHP_BINARY,
                        base_path('tests/Fixtures/m1-report-worker.php'),
                    ],
                    base_path(),
                    $environment,
                );

                $process->setInput(json_encode([
                    ...$command,
                    'work_order_id' => $workOrder->id,
                    'barrier_table' => $barrierTable,
                    'barrier_id' => $barrierId ?? $workOrder->id,
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
                $this->assertSame($snapshotCount, $result['snapshot_report_count']);
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

    /** @return array{User, WorkOrder, WorkOrderScopeItem} */
    private function context(): array
    {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $technician = User::factory()->create([
            'role' => 'teknisi',
        ]);

        $customer = Customer::query()->create([
            'name' => 'Customer Draft Report',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Site Draft Report',
            'address' => 'Bekasi',
        ]);

        $workOrder = app(CreateWorkOrderService::class)->create(
            $coordinator,
            [
                'command_key' => (string) Str::uuid(),
                'origin_kind' => 'manual',
                'manual_reason' => 'Pengujian draft laporan M1.',
                'initial_scope' => [
                    'description' => 'Pulihkan kamera CCTV.',
                    'acceptance_criteria' => 'Live feed tampil.',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Pengujian draft laporan',
                'description' => 'Kamera offline.',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ],
        );

        $workOrder->technicians()->attach($technician, [
            'assigned_at' => now(),
        ]);

        // Fixture menyiapkan state eksekusi agar tes fokus pada draft report.
        $workOrder->forceFill([
            'status' => 'in_progress',
            'lock_version' => (int) $workOrder->lock_version + 1,
        ])->save();

        return [
            $technician,
            $workOrder->refresh(),
            $workOrder->scopeItems()->sole(),
        ];
    }
}
