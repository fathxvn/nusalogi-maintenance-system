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
use App\Services\ServiceReportWorkflowService;
use App\Services\VisitBookingExecutionService;
use App\Services\VisitSchedulingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServiceReportWorkflowConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('operations')]
    public function test_concurrent_submit_review_and_successor_commands_have_one_winner(string $operation): void
    {
        [$tech, $wo, $scope] = $this->context();
        $reviewer = User::factory()->create(['role' => 'koordinator']);
        $otherReviewer = User::factory()->create(['role' => 'koordinator']);
        $report = app(ServiceReportDraftService::class)->createDraft($tech, $wo->id, $scope->id, [
            'command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
            'diagnosis' => 'Kabel', 'action_taken' => 'Pasang', 'result' => 'Normal',
            'checklist_snapshot' => [['code' => 'test', 'label' => 'Uji fungsi', 'outcome' => 'pass']],
        ]);
        $workflow = app(ServiceReportWorkflowService::class);
        if ($operation !== 'submit') {
            $report = $workflow->submit($tech, $wo->id, $report->id, $this->payload($report));
        }
        if ($operation === 'revise') {
            $report = $workflow->review($reviewer, $wo->id, $report->id, [...$this->payload($report),
                'decision' => 'needs_revision', 'category' => 'document_fix', 'reason' => 'Perjelas hasil']);
        }
        $actor = $operation === 'review' ? $reviewer : $tech;
        $command = ['action' => $operation, 'actor_id' => $actor->id, 'report_id' => $report->id,
            'payload' => [...$this->payload($report), ...($operation === 'review' ? ['decision' => 'approved'] : [])]];
        $other = $command;
        $other['actor_id'] = $operation === 'review' ? $otherReviewer->id : $actor->id;
        $other['payload']['command_key'] = (string) Str::uuid();
        $before = OperationHistory::query()->count();

        $results = $this->race($wo, ['first' => $command, 'second' => $other]);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        $loser = $results['first']['ok'] ? 'second' : 'first';
        $this->assertSame(409, $results[$loser]['status']);
        $this->assertDatabaseCount('operation_history', $before + 1);
        if ($operation === 'revise') {
            $next = $report->nextRevision()->sole();
            $this->assertSame(2, $next->revision_no);
            $this->assertSame('draft', $next->review_status);
            $this->assertSame('needs_revision', $report->fresh()->review_status);
            $this->assertDatabaseCount('service_reports', 2);
        } else {
            $this->assertSame($operation === 'submit' ? 'submitted' : 'approved', $report->fresh()->review_status);
            $this->assertSame($operation === 'submit' ? 'pending_review' : 'awaiting_acceptance', $wo->fresh()->status);
            $this->assertDatabaseCount('service_reports', 1);
        }
    }

    public function test_simultaneous_identical_submit_retries_freeze_once(): void
    {
        [$tech, $wo, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($tech, $wo->id, $scope->id, [
            'command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
            'diagnosis' => 'Kabel', 'action_taken' => 'Pasang', 'result' => 'Normal',
            'checklist_snapshot' => [['code' => 'test', 'label' => 'Uji fungsi', 'outcome' => 'pass']],
        ]);
        $command = ['action' => 'submit', 'actor_id' => $tech->id, 'report_id' => $report->id, 'payload' => $this->payload($report)];
        $results = $this->race($wo, ['first' => $command, 'second' => $command]);
        $this->assertTrue($results['first']['ok']);
        $this->assertTrue($results['second']['ok']);
        $this->assertSame(1, $report->fresh()->lock_version);
        $this->assertSame(1, OperationHistory::query()->where('action', 'report.submit')->count());
        $this->assertSame(1, $wo->updates()->where('to_status', 'pending_review')->count());
    }

    public function test_technical_review_waits_for_running_booking_without_partial_decision(): void
    {
        [$tech, $reviewer, $wo, $report, $booking] = $this->reworkScenario();
        $execution = app(VisitBookingExecutionService::class);
        $execution->start($tech, $booking->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 1, 'expected_booking_status' => 'confirmed']);
        $input = [...$this->payload($report->fresh()), 'decision' => 'needs_revision', 'category' => 'technical_rework', 'reason' => 'Perbaiki teknis'];
        $before = OperationHistory::query()->count();
        try {
            app(ServiceReportWorkflowService::class)->review($reviewer, $wo->id, $report->id, $input);
            $this->fail('Running booking must block technical review.');
        } catch (ValidationException $exception) {
            $this->assertSame(['ACTIVE_BOOKING_BLOCKS_REWORK'], $exception->errors()['work_order_id']);
        }
        $this->assertSame('submitted', $report->fresh()->review_status);
        $this->assertNull($report->fresh()->reviewed_at);
        $this->assertNull($report->fresh()->reviewed_by);
        $this->assertSame('in_progress', $wo->fresh()->status);
        $this->assertDatabaseCount('operation_history', $before);
        $execution->complete($tech, $booking->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 2, 'expected_booking_status' => 'in_progress']);
        app(ServiceReportWorkflowService::class)->review($reviewer, $wo->id, $report->id, $input);
        $this->assertSame('rework', $wo->fresh()->status);
        $this->assertSame('completed', $booking->fresh()->status);
    }

    #[DataProvider('executionRaces')]
    public function test_technical_review_racing_execution_never_strands_a_running_booking(string $action): void
    {
        [$tech, $reviewer, $wo, $report, $booking] = $this->reworkScenario();
        if ($action === 'complete') {
            app(VisitBookingExecutionService::class)->start($tech, $booking->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 1, 'expected_booking_status' => 'confirmed']);
        }
        $before = OperationHistory::query()->count();
        $results = $this->race($wo, [
            'review' => ['action' => 'review', 'actor_id' => $reviewer->id, 'report_id' => $report->id,
                'payload' => [...$this->payload($report->fresh()), 'decision' => 'needs_revision', 'category' => 'technical_rework', 'reason' => 'Perbaiki teknis']],
            'execution' => ['action' => 'booking.'.$action, 'actor_id' => $tech->id, 'booking_id' => $booking->id,
                'payload' => ['command_key' => (string) Str::uuid(), 'expected_visit_version' => $action === 'start' ? 1 : 2, 'expected_booking_status' => $action === 'start' ? 'confirmed' : 'in_progress']],
        ]);
        $this->assertFalse($booking->fresh()->status === 'in_progress' && $wo->fresh()->status === 'rework');
        $this->assertSame($results['review']['ok'] ? 'needs_revision' : 'submitted', $report->fresh()->review_status);
        $this->assertSame($results['review']['ok'], $report->fresh()->reviewed_at !== null);
        $this->assertSame($results['review']['ok'] ? 1 : 0, OperationHistory::query()->where('action', 'report.review')->count());
        $this->assertDatabaseCount('operation_history', $before + count(array_filter($results, fn (array $result): bool => $result['ok'])));
    }

    public static function executionRaces(): array
    {
        return [['start'], ['complete']];
    }

    private function reworkScenario(): array
    {
        [$tech, $wo, $scope] = $this->context();
        $scope->replicate()->forceFill(['scope_no' => 2, 'created_at' => now()])->save();
        $report = app(ServiceReportDraftService::class)->createDraft($tech, $wo->id, $scope->id, [
            'command_key' => (string) Str::uuid(), 'expected_work_order_version' => $wo->lock_version,
            'diagnosis' => 'Kabel', 'action_taken' => 'Pasang', 'result' => 'Normal',
            'checklist_snapshot' => [['code' => 'test', 'label' => 'Uji', 'outcome' => 'pass']]]);
        $report = app(ServiceReportWorkflowService::class)->submit($tech, $wo->id, $report->id, $this->payload($report));
        $reviewer = User::factory()->create(['role' => 'koordinator']);
        $visit = app(VisitSchedulingService::class)->createDraft($reviewer, $wo->id, ['command_key' => (string) Str::uuid(),
            'starts_at' => '2026-11-20T09:00+07:00', 'ends_at' => '2026-11-20T11:00+07:00', 'zone_name' => 'Asia/Jakarta']);
        app(VisitSchedulingService::class)->confirm($reviewer, $visit->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$tech->id]]);

        return [$tech, $reviewer, $wo->fresh(), $report, $visit->bookings()->sole()];
    }

    public static function operations(): array
    {
        return ['submit' => ['submit'], 'reviewers' => ['review'], 'successor creators' => ['revise']];
    }

    private function payload(ServiceReport $report): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_report_version' => $report->lock_version,
            'expected_work_order_version' => (int) $report->workOrder->lock_version];
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
