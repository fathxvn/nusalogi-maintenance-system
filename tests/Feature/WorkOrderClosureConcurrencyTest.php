<?php

namespace Tests\Feature;

use App\Models\BastItem;
use App\Models\BastVersion;
use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\BastService;
use App\Services\CreateWorkOrderService;
use App\Services\ServiceReportDraftService;
use App\Services\ServiceReportWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkOrderClosureConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_closers_prove_transaction_overlap_and_only_one_status_history_and_proof_commit(): void
    {
        Storage::fake('evidence');
        [$actor, , $wo, $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        $commands = [];
        foreach (['first', 'second'] as $name) {
            $commands[$name] = ['action' => 'wo.close', 'actor_id' => $actor->id,
                'payload' => ['command_key' => (string) Str::uuid(), 'expected_work_order_version' => $wo->lock_version]];
        }
        $before = OperationHistory::query()->count();
        $results = $this->race($wo, $commands, 'customers', (int) $wo->site->customer_id);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        $loser = $results['first']['ok'] ? 'second' : 'first';
        $this->assertSame(['WORK_ORDER_VERSION_CONFLICT'], $results[$loser]['errors']['expected_work_order_version']);
        $this->assertSame('closed', $wo->fresh()->status);
        $this->assertDatabaseCount('operation_history', $before + 1);
        $this->assertSame(1, $wo->updates()->where('to_status', 'closed')->count());
        $this->assertCount(1, OperationHistory::query()->where('action', 'work_order.close')->sole()->facts['closure_evidence']);
    }

    public function test_closure_racing_a_direct_scope_writer_never_closes_with_an_unaccepted_scope(): void
    {
        Storage::fake('evidence');
        [$actor, , $wo, $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        $commands = [
            'close' => ['action' => 'wo.close', 'actor_id' => $actor->id,
                'payload' => ['command_key' => (string) Str::uuid(), 'expected_work_order_version' => $wo->lock_version]],
            'append' => ['action' => 'scope.append', 'actor_id' => $actor->id, 'payload' => []],
        ];
        $results = $this->race($wo, $commands);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']));
        if ($results['close']['ok']) {
            $this->assertSame('closed', $wo->fresh()->status);
            $this->assertSame(['TERMINAL_WORK_ORDER_SCOPE_IMMUTABLE'], $results['append']['errors']['scope']);
            $this->assertSame(1, $wo->scopeItems()->count());
        } else {
            $this->assertSame(['ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED'], $results['close']['errors']['work_order_id']);
            $this->assertSame('awaiting_acceptance', $wo->fresh()->status);
            $this->assertSame(2, $wo->scopeItems()->count());
            $this->assertNull($wo->fresh()->closed_at);
        }
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
                    'evidence_root' => Storage::disk('evidence')->path(''),
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

    private function approved(?WorkOrder $shared = null): array
    {
        [$tech, $wo, $scope] = $this->context();
        if ($shared) {
            $wo->forceFill(['customer_site_id' => $shared->customer_site_id, 'acceptance_context_ref' => $shared->acceptance_context_ref])->save();
        }
        $report = app(ServiceReportDraftService::class)->createDraft($tech, $wo->id, $scope->id, $this->content($wo));
        $workflow = app(ServiceReportWorkflowService::class);
        $report = $workflow->submit($tech, $wo->id, $report->id, $this->command($report));
        $actor = User::factory()->create(['role' => 'koordinator']);
        $report = $workflow->review($actor, $wo->id, $report->id, [...$this->command($report), 'decision' => 'approved']);

        return [$actor, $tech, $wo->refresh(), $report];
    }

    private function package(array $reports): array
    {
        $wo = $reports[0]->workOrder->fresh();
        $versions = [];
        foreach ($reports as $report) {
            $versions[$report->work_order_id] = (int) $report->workOrder->fresh()->lock_version;
        }

        return ['command_key' => (string) Str::uuid(), 'customer_id' => $wo->site->customer_id,
            'acceptance_context_ref' => $wo->acceptance_context_ref, 'report_ids' => array_map(fn (ServiceReport $report): int => $report->id, $reports), 'work_order_versions' => $versions];
    }

    private function upload(User $actor, BastVersion $version, string $purpose): PrivateEvidenceFile
    {
        $file = UploadedFile::fake()->createWithContent($purpose.'.pdf', "%PDF-1.4\nTest signed or issued evidence\n%%EOF");

        return app(BastService::class)->upload($actor, $version->id, $file, ['command_key' => (string) Str::uuid(),
            'expected_version' => $version->lock_version, 'purpose' => $purpose, 'scope_confirmed' => true, 'sha256' => hash_file('sha256', $file->getRealPath())]);
    }

    private function signer(BastVersion $version): array
    {
        $sites = $version->items()->with('reportRevision.workOrder')->get()->map(fn (BastItem $item): int => (int) $item->reportRevision->workOrder->customer_site_id)->unique()->values()->all();

        return ['signer_name' => 'PIC Customer', 'signer_capacity' => 'PIC berwenang seluruh paket', 'authority_ref' => 'Surat kuasa QA fixture', 'authorized_site_ids' => $sites];
    }

    private function issue(User $actor, BastVersion $version): BastVersion
    {
        $file = $this->upload($actor, $version, 'issued');

        return app(BastService::class)->issue($actor, $version->id, [...$this->signer($version), 'command_key' => (string) Str::uuid(),
            'expected_version' => $version->lock_version, 'evidence_file_id' => $file->id, 'scope_confirmed' => true]);
    }

    private function decision(User $actor, BastVersion $version): array
    {
        $file = $this->upload($actor, $version, 'decision');

        return [...$this->signer($version), 'command_key' => (string) Str::uuid(), 'expected_version' => $version->lock_version,
            'decision' => 'accepted', 'evidence_file_id' => $file->id, 'decided_at' => now()->toISOString()];
    }

    private function command(ServiceReport $report, array $extra = []): array
    {
        return [...$extra, 'command_key' => (string) Str::uuid(), 'expected_report_version' => $report->lock_version,
            'expected_work_order_version' => (int) $report->workOrder->lock_version];
    }

    private function content(WorkOrder $wo): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
            'diagnosis' => 'Kabel terlepas', 'action_taken' => 'Pasang kembali', 'result' => 'Live feed normal',
            'checklist_snapshot' => [['code' => 'feed', 'label' => 'Live feed', 'outcome' => 'pass']]];
    }

    private function draft(): array
    {
        [$actor, $wo, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($actor, $wo->id, $scope->id, $this->content($wo));

        return [$actor, $wo, $scope, $report];
    }

    private function assertConflict(callable $operation, string $code): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertContains([$code], array_values($exception->errors()));

            return;
        }
        $this->fail('Expected a domain conflict.');
    }

    private function assertImmutable(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }
        $this->fail('Expected database immutability guard.');
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
