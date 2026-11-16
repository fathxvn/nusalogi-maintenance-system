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
use App\Services\VisitBookingExecutionService;
use App\Services\VisitSchedulingService;
use App\Services\WorkOrderLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_closure_records_all_scope_evidence_without_reading_or_writing_finance(): void
    {
        Storage::fake('evidence');
        [$actor, , $wo, $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        $accepted = app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        $wo->forceFill(['completed_at' => '2026-10-01 00:00:00', 'verified_by' => $actor->id])->save();
        $input = $this->woCommand($wo->fresh());
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $closed = app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $input);
        $retry = app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $input);

        $this->assertSame('closed', $closed->status);
        $this->assertSame($closed->closed_at->toISOString(), $retry->closed_at->toISOString());
        $this->assertSame('2026-10-01 00:00:00', $closed->completed_at->toDateTimeString());
        $this->assertSame($actor->id, (int) $closed->verified_by);
        $this->assertSame($input['expected_work_order_version'] + 1, $closed->lock_version);
        $history = OperationHistory::query()->where('action', 'work_order.close')->sole();
        $proof = $history->facts['closure_evidence'];
        $this->assertCount(1, $proof);
        $this->assertSame($report->scope_item_id, $proof[0]['scope_item_id']);
        $this->assertSame($report->id, $proof[0]['report_revision_id']);
        $this->assertSame($accepted->items()->sole()->id, $proof[0]['bast_item_id']);
        $this->assertSame($accepted->id, $proof[0]['bast_version_id']);
        $this->assertSame((int) $accepted->issued_file_id, $proof[0]['issued_file_id']);
        $this->assertSame((int) $accepted->decision_file_id, $proof[0]['decision_file_id']);
        $this->assertSame('manual_queue', $history->facts['ticket_resolution']);
        $this->assertSame(1, $wo->updates()->where('to_status', 'closed')->count());
        foreach ($statements as $sql) {
            $this->assertDoesNotMatchRegularExpression('/invoice|payment/i', $sql);
        }
    }

    public function test_one_accepted_scope_does_not_close_another_required_scope(): void
    {
        Storage::fake('evidence');
        [$actor, , $wo, $report] = $this->approved();
        $scope = $report->scopeItem->replicate();
        $scope->forceFill(['scope_no' => 2, 'created_at' => now()])->save();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $this->woCommand($wo->fresh())), 'ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED');
        $this->assertSame('awaiting_acceptance', $wo->fresh()->status);
        $this->assertNull($wo->fresh()->closed_at);
        $this->assertDatabaseMissing('operation_history', ['action' => 'work_order.close']);
    }

    public function test_pending_and_rejected_acceptance_never_close_work_order(): void
    {
        Storage::fake('evidence');
        [$actor, , $wo, $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $this->woCommand($wo)), 'ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED');
        app(BastService::class)->decide($actor, $version->id, [...$this->decision($actor, $version), 'decision' => 'rejected', 'category' => 'document_fix', 'reason' => 'Perbaiki dokumen']);
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $this->woCommand($wo->fresh())), 'ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED');
        $this->assertNull($wo->fresh()->closed_at);
    }

    public function test_closure_requires_nonempty_required_active_scopes_and_forbids_exception(): void
    {
        [$actor, , $wo, $report] = $this->approved();
        $report->scopeItem->forceFill(['is_required' => false])->save();
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $this->woCommand($wo)), 'WORK_ORDER_M1_BASELINE_REQUIRED');
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->close($actor, $wo->id, [...$this->woCommand($wo), 'closure_mode' => 'exception']), 'EXCEPTION_CLOSURE_DISABLED');
        $this->assertNull($wo->fresh()->closed_at);
    }

    public function test_closure_history_failure_rolls_back_status_timestamp_and_status_history(): void
    {
        Storage::fake('evidence');
        [$actor, , $wo, $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        $before = $wo->fresh()->getAttributes();
        $updates = $wo->updates()->count();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Closure history failure');
            }
        });
        $input = $this->woCommand($wo);
        try {
            app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $input);
            $this->fail('History failure must roll back closure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Closure history failure', $exception->getMessage());
        }
        $this->assertSame($before, $wo->fresh()->getAttributes());
        $this->assertSame($updates, $wo->updates()->count());
        $this->assertSame('closed', app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $input)->status);
    }

    public function test_member_can_hold_and_resume_only_the_audited_previous_state(): void
    {
        [$tech, $wo] = $this->context();
        $service = app(WorkOrderLifecycleService::class);
        $hold = $this->woCommand($wo, ['reason' => 'Site tidak dapat diakses']);
        $held = $service->change($tech, $wo->id, 'hold', $hold);
        $this->assertSame('on_hold', $held->status);
        $replayed = $service->change($tech, $wo->id, 'hold', $hold);
        $this->assertSame($held->lock_version, $replayed->lock_version);
        $resumed = $service->change($tech, $wo->id, 'resume', $this->woCommand($held, ['reason' => 'Akses site pulih', 'status' => 'closed']));
        $this->assertSame('in_progress', $resumed->status);
        $this->assertSame(1, $wo->updates()->where('to_status', 'on_hold')->count());
        $this->assertSame(1, $wo->updates()->where('from_status', 'on_hold')->where('to_status', 'in_progress')->count());
    }

    public function test_pre_execution_cancel_preserves_membership_and_actual_time_provenance(): void
    {
        [$tech, $wo, $scope] = $this->context();
        $actor = User::factory()->create(['role' => 'koordinator']);
        $wo->forceFill(['status' => 'assigned'])->save();
        $visit = app(VisitSchedulingService::class)->createDraft($actor, $wo->id, ['command_key' => (string) Str::uuid(),
            'starts_at' => '2026-11-20T09:00+07:00', 'ends_at' => '2026-11-20T11:00+07:00', 'zone_name' => 'Asia/Jakarta']);
        $visit = app(VisitSchedulingService::class)->confirm($actor, $visit->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$tech->id]]);
        $cancelled = app(WorkOrderLifecycleService::class)->change($actor, $wo->id, 'cancel', $this->woCommand($wo->fresh(), ['reason' => 'Customer membatalkan sebelum kunjungan']));
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('cancelled', $visit->fresh()->status);
        $booking = $visit->bookings()->sole();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNull($booking->actual_start_at);
        $this->assertNull($booking->actual_end_at);
        $this->assertSame(1, $wo->technicians()->count());
        $this->assertSame('cancelled', $scope->fresh()->disposition);
        $this->assertTrue($scope->fresh()->is_required);
        $this->assertNull($wo->fresh()->completed_at);
    }

    public function test_running_booking_blocks_hold_and_cancellation_without_fabricating_completion(): void
    {
        [$tech, $wo] = $this->context();
        $actor = User::factory()->create(['role' => 'koordinator']);
        $wo->forceFill(['status' => 'assigned'])->save();
        $visit = app(VisitSchedulingService::class)->createDraft($actor, $wo->id, ['command_key' => (string) Str::uuid(),
            'starts_at' => '2026-11-20T09:00+07:00', 'ends_at' => '2026-11-20T11:00+07:00', 'zone_name' => 'Asia/Jakarta']);
        app(VisitSchedulingService::class)->confirm($actor, $visit->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$tech->id]]);
        $booking = $visit->bookings()->sole();
        app(VisitBookingExecutionService::class)->start($tech, $booking->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 1, 'expected_booking_status' => 'confirmed']);
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->change($tech, $wo->id, 'hold', $this->woCommand($wo->fresh(), ['reason' => 'Stop'])), 'ACTIVE_BOOKING_BLOCKS_HOLD');
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->change($actor, $wo->id, 'cancel', $this->woCommand($wo->fresh(), ['reason' => 'Cancel running work'])), 'CANCELLATION_AUTHORITY_REQUIRED');
        $this->assertNull($booking->fresh()->actual_end_at);
        $this->assertSame('in_progress', $wo->fresh()->status);
    }

    public function test_common_acceptance_context_is_explicit_audited_and_cannot_change_after_a_bast_reference(): void
    {
        [$tech, $wo] = $this->context();
        $actor = User::factory()->create(['role' => 'koordinator']);
        $changed = app(WorkOrderLifecycleService::class)->change($actor, $wo->id, 'context', $this->woCommand($wo, [
            'reason' => 'Paket serah terima site yang disepakati', 'acceptance_context_ref' => 'handover:document-123',
        ]));
        $this->assertSame('handover:document-123', $changed->acceptance_context_ref);
        $history = OperationHistory::query()->where('action', 'work_order.context')->sole();
        $this->assertStringStartsWith('manual:', $history->facts['previous_context']);
        $this->assertSame('manual', $changed->origin_kind);
        $report = app(ServiceReportDraftService::class)->createDraft($tech, $wo->id, $wo->scopeItems()->sole()->id, $this->content($changed));
        $report = app(ServiceReportWorkflowService::class)->submit($tech, $wo->id, $report->id, $this->command($report));
        $report = app(ServiceReportWorkflowService::class)->review($actor, $wo->id, $report->id, [...$this->command($report), 'decision' => 'approved']);
        app(BastService::class)->createDraft($actor, $this->package([$report]));
        $this->assertConflict(fn () => app(WorkOrderLifecycleService::class)->change($actor, $wo->id, 'context', $this->woCommand($wo->fresh(), [
            'reason' => 'Attempt to change referenced context', 'acceptance_context_ref' => 'different-context',
        ])), 'CONTEXT_REFERENCED_BY_BAST');
        $this->assertSame('handover:document-123', $wo->fresh()->acceptance_context_ref);
    }

    #[DataProvider('changes')]
    public function test_history_failure_rolls_back_lifecycle_and_retries_once(string $action): void
    {
        [, $wo] = $this->context();
        $actor = User::factory()->create(['role' => 'koordinator']);
        $service = app(WorkOrderLifecycleService::class);
        if ($action === 'resume') {
            $wo = $service->change($actor, $wo->id, 'hold', $this->woCommand($wo, ['reason' => 'Hold fixture']));
        } elseif ($action === 'cancel') {
            $wo->forceFill(['status' => 'assigned'])->save();
        }
        $input = $this->woCommand($wo->fresh(), ['reason' => 'Audited lifecycle change']);
        if ($action === 'context') {
            $input['acceptance_context_ref'] = 'shared:handover';
        }
        $snapshot = fn (): array => [$wo->fresh()->getAttributes(), $wo->scopeItems()->get()->toArray(), $wo->updates()->count(), OperationHistory::query()->count()];
        $before = $snapshot();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Lifecycle history failure');
            }
        });
        try {
            $service->change($actor, $wo->id, $action, $input);
            $this->fail('History failure must roll back lifecycle.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Lifecycle history failure', $exception->getMessage());
        }
        $this->assertSame($before, $snapshot());
        $service->change($actor, $wo->id, $action, $input);
        $this->assertSame($before[3] + 1, OperationHistory::query()->count());
    }

    public static function changes(): array
    {
        return ['hold' => ['hold'], 'resume' => ['resume'], 'cancel' => ['cancel'], 'context' => ['context']];
    }

    public function test_hold_replay_rechecks_membership_and_account_and_changed_key_payload_conflicts(): void
    {
        [$actor, $wo] = $this->context();
        $service = app(WorkOrderLifecycleService::class);
        $input = $this->woCommand($wo, ['reason' => 'Akses site tertunda']);
        $held = $service->change($actor, $wo->id, 'hold', $input);
        $this->assertConflict(fn () => $service->change($actor, $wo->id, 'hold', [...$input, 'reason' => 'Changed']), 'COMMAND_KEY_REUSED');
        $wo->technicians()->detach($actor);
        try {
            $service->change($actor, $wo->id, 'hold', $input);
            $this->fail('Revoked member replay must fail.');
        } catch (AuthorizationException) {
            $this->assertSame($held->lock_version, $wo->fresh()->lock_version);
        }
        $this->assertSame(1, OperationHistory::query()->where('action', 'work_order.hold')->count());
    }

    #[DataProvider('forbiddenCloserRoles')]
    public function test_only_coordinator_can_close_even_via_direct_service(string $role): void
    {
        [, $wo] = $this->context();
        $actor = User::factory()->create(['role' => $role]);
        $this->expectException(AuthorizationException::class);
        try {
            app(WorkOrderLifecycleService::class)->close($actor, $wo->id, $this->woCommand($wo));
        } finally {
            $this->assertNull($wo->fresh()->closed_at);
            $this->assertDatabaseMissing('operation_history', ['action' => 'work_order.close']);
        }
    }

    public static function forbiddenCloserRoles(): array
    {
        return ['admin' => ['admin'], 'technician' => ['teknisi'], 'unsupported' => ['legacy_role']];
    }

    private function woCommand(WorkOrder $wo, array $extra = []): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_work_order_version' => $wo->lock_version, ...$extra];
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
