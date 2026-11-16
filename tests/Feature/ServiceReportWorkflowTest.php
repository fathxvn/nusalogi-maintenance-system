<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use App\Services\MaterialUsageService;
use App\Services\ServiceReportDraftService;
use App\Services\ServiceReportWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ServiceReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_freezes_content_and_ledger_snapshot_without_duplicating_usage(): void
    {
        [$actor, $wo, $scope, $report] = $this->draft();
        $movement = app(MaterialUsageService::class)->record($actor, $wo->id, $report->id, [
            'command_key' => (string) Str::uuid(), 'expected_report_version' => 0, 'movement_kind' => 'consume',
            'item_description' => 'Kabel', 'unit_code' => 'meter', 'quantity' => '0.125', 'supply_source' => 'company', 'disposition' => 'covered',
        ]);
        $this->freezeTime();
        $input = $this->command($report->fresh());
        $service = app(ServiceReportWorkflowService::class);

        $submitted = $service->submit($actor, $wo->id, $report->id, [...$input, 'usage_snapshot' => ['forged'], 'reviewed_by' => $actor->id]);
        $replayed = $service->submit($actor, $wo->id, $report->id, $input);

        $this->assertSame($submitted->id, $replayed->id);
        $this->assertSame('submitted', $submitted->review_status);
        $this->assertSame(now()->toDateTimeString(), $submitted->submitted_at->toDateTimeString());
        $this->assertSame($movement->id, $submitted->usage_snapshot[0]['id']);
        $this->assertSame('0.125', $submitted->usage_snapshot[0]['quantity']);
        $this->assertSame(2, $submitted->lock_version);
        $this->assertNull($submitted->reviewed_by);
        $this->assertDatabaseCount('material_usages', 1);
        $this->assertSame('pending_review', $wo->fresh()->status);
        $this->assertNotNull($wo->fresh()->field_finished_at);
        $this->assertNull($wo->fresh()->completed_at);
        $this->assertSame(1, OperationHistory::query()->where('action', 'report.submit')->count());
        $this->assertImmutable(fn () => $submitted->forceFill(['diagnosis' => 'Replaced'])->save(), 'SUBMITTED_REPORT_IMMUTABLE');
    }

    public function test_document_fix_preserves_original_review_and_creates_a_linear_successor(): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $service = app(ServiceReportWorkflowService::class);
        $report = $service->submit($actor, $wo->id, $report->id, $this->command($report));
        $reviewer = User::factory()->create(['role' => 'koordinator']);
        $review = $this->command($report, ['decision' => 'needs_revision', 'category' => 'document_fix', 'reason' => 'Perjelas hasil uji.']);
        $rejected = $service->review($reviewer, $wo->id, $report->id, $review);
        $old = $rejected->getAttributes();
        $this->assertSame('pending_review', $wo->fresh()->status);
        $next = $service->revise($actor, $wo->id, $report->id, $this->command($rejected));
        $this->assertSame(2, $next->revision_no);
        $this->assertSame($report->id, $next->previous_revision_id);
        $this->assertSame($actor->id, (int) $next->created_by);
        $this->assertSame('draft', $next->review_status);
        $this->assertNull($next->submitted_at);
        $this->assertNull($next->usage_snapshot);
        $this->assertSame($old, $report->fresh()->getAttributes());
        $this->assertConflict(fn () => $service->review($reviewer, $wo->id, $report->id,
            $this->command($report->fresh(), ['decision' => 'approved'])), 'REPORT_REVISION_CONFLICT');
        $next = $service->submit($actor, $wo->id, $next->id, $this->command($next));
        $approved = $service->review($reviewer, $wo->id, $next->id, $this->command($next, ['decision' => 'approved']));
        $this->assertSame('approved', $approved->review_status);
        $this->assertSame('awaiting_acceptance', $wo->fresh()->status);
        $this->assertSame(1, $wo->updates()->where('to_status', 'pending_review')->count());
        $this->assertSame('needs_revision', $report->fresh()->review_status);
    }

    public function test_two_required_scopes_determine_qa_state_and_no_single_report_finishes_work_order(): void
    {
        [$actor, $wo, $scope, $first] = $this->draft();
        $secondScope = $scope->replicate();
        $secondScope->forceFill(['scope_no' => 2, 'created_at' => now()])->save();
        $second = app(ServiceReportDraftService::class)->createDraft($actor, $wo->id, $secondScope->id, $this->content($wo));
        $reviewer = User::factory()->create(['role' => 'koordinator']);
        $service = app(ServiceReportWorkflowService::class);
        $first = $service->submit($actor, $wo->id, $first->id, $this->command($first));
        $this->assertSame('in_progress', $wo->fresh()->status);
        $service->review($reviewer, $wo->id, $first->id, $this->command($first, ['decision' => 'approved']));
        $this->assertSame('in_progress', $wo->fresh()->status);
        $second = $service->submit($actor, $wo->id, $second->id, $this->command($second));
        $this->assertSame('pending_review', $wo->fresh()->status);
        $service->review($reviewer, $wo->id, $second->id, $this->command($second, ['decision' => 'approved']));
        $this->assertSame('awaiting_acceptance', $wo->fresh()->status);
        $this->assertNull($wo->fresh()->closed_at);
    }

    public function test_technical_rework_requires_execution_before_resubmission(): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $service = app(ServiceReportWorkflowService::class);
        $report = $service->submit($actor, $wo->id, $report->id, $this->command($report));
        $reviewer = User::factory()->create(['role' => 'koordinator']);
        $rejected = $service->review($reviewer, $wo->id, $report->id, $this->command($report, [
            'decision' => 'needs_revision', 'category' => 'technical_rework', 'reason' => 'Live feed masih terputus.',
        ]));
        $this->assertSame('rework', $wo->fresh()->status);
        $next = $service->revise($actor, $wo->id, $report->id, $this->command($rejected));
        $this->assertConflict(fn () => $service->submit($actor, $wo->id, $next->id, $this->command($next)), 'REWORK_MUST_START_BEFORE_SUBMISSION');
        $this->assertSame('draft', $next->fresh()->review_status);
        $this->assertSame('needs_revision', $report->fresh()->review_status);
    }

    public function test_reviewer_cannot_review_own_report_after_role_change(): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $service = app(ServiceReportWorkflowService::class);
        $report = $service->submit($actor, $wo->id, $report->id, $this->command($report));
        $actor->forceFill(['role' => 'koordinator'])->save();
        $this->expectException(AuthorizationException::class);
        try {
            $service->review($actor, $wo->id, $report->id, $this->command($report, ['decision' => 'approved']));
        } finally {
            $this->assertSame('submitted', $report->fresh()->review_status);
            $this->assertNull($report->fresh()->reviewed_by);
        }
    }

    public function test_submission_requires_narrative_and_checklist(): void
    {
        [$actor, $wo, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($actor, $wo->id, $scope->id, [
            'command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
        ]);
        try {
            app(ServiceReportWorkflowService::class)->submit($actor, $wo->id, $report->id, $this->command($report));
            $this->fail('Incomplete submission must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('diagnosis', $exception->errors());
            $this->assertArrayHasKey('checklist_snapshot', $exception->errors());
        }
        $this->assertSame('draft', $report->fresh()->review_status);
        $this->assertSame('in_progress', $wo->fresh()->status);
    }

    #[DataProvider('operations')]
    public function test_history_failure_rolls_back_work_order_report_and_successor(string $operation): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $reviewer = User::factory()->create(['role' => 'koordinator']);
        $service = app(ServiceReportWorkflowService::class);
        if ($operation !== 'submit') {
            $report = $service->submit($actor, $wo->id, $report->id, $this->command($report));
        }
        if ($operation === 'revise') {
            $report = $service->review($reviewer, $wo->id, $report->id, $this->command($report, [
                'decision' => 'needs_revision', 'category' => 'document_fix', 'reason' => 'Perbaiki dokumen',
            ]));
        }
        $input = $this->command($report, $operation === 'review' ? ['decision' => 'approved'] : []);
        $before = [ServiceReport::query()->get()->toArray(), $wo->fresh()->getAttributes(), OperationHistory::query()->count(), $wo->updates()->count()];
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Workflow audit failure');
            }
        });
        $run = fn () => $service->{$operation}($operation === 'review' ? $reviewer : $actor, $wo->id, $report->id, $input);
        try {
            $run();
            $this->fail('Audit failure must roll back workflow.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Workflow audit failure', $exception->getMessage());
        }
        $this->assertSame($before, [ServiceReport::query()->get()->toArray(), $wo->fresh()->getAttributes(), OperationHistory::query()->count(), $wo->updates()->count()]);
        $run();
        $this->assertSame($before[2] + 1, OperationHistory::query()->count());
    }

    public static function operations(): array
    {
        return ['submit' => ['submit'], 'review' => ['review'], 'revise' => ['revise']];
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
