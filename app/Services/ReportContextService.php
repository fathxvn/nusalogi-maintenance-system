<?php

namespace App\Services;

use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class ReportContextService
{
    public function __construct(private readonly M1CommandService $commands) {}

    public function visibleReport(User $actor, int $workOrderId, int $reportId, bool $authorOnly = true): ServiceReport
    {
        $workOrder = WorkOrder::query()->visibleTo($actor)->findOrFail($workOrderId);
        $report = $workOrder->reports()->findOrFail($reportId);
        if ($authorOnly && (int) $report->created_by !== (int) $actor->id) {
            throw new AuthorizationException('Hanya penulis laporan yang boleh mengubah revisi ini.');
        }

        return $report;
    }

    /** @return array{workOrder: WorkOrder, scopes: Collection<int, WorkOrderScopeItem>} */
    public function lockRoots(User $actor, int $workOrderId, ?int $reportId = null, bool $authorOnly = true): array
    {
        $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
        if ($actor->hasRole('teknisi') && ! $workOrder->technicians()->where('users.id', $actor->id)->exists()) {
            throw new AuthorizationException('Hanya teknisi anggota WO yang boleh mengubah hasil pekerjaan.');
        }
        $scopes = $workOrder->scopeItems()->orderBy('id')->lockForUpdate()->get();
        if ($reportId !== null) {
            $report = $workOrder->reports()->findOrFail($reportId);
            if ($authorOnly && (int) $report->created_by !== (int) $actor->id) {
                throw new AuthorizationException('Hanya penulis laporan yang boleh mengubah revisi ini.');
            }
        }

        return compact('workOrder', 'scopes');
    }

    /** @param Collection<int, WorkOrderScopeItem> $scopes */
    public function assertBaseline(WorkOrder $workOrder, Collection $scopes): void
    {
        if (! in_array($workOrder->origin_kind, ['ticket', 'manual'], true) || blank($workOrder->acceptance_context_ref)
            || ! $scopes->contains(fn (WorkOrderScopeItem $scope): bool => $scope->is_required && $scope->disposition === 'active')) {
            $this->commands->conflict('work_order_id', 'WORK_ORDER_M1_BASELINE_REQUIRED');
        }
    }

    /** @param array{workOrder: WorkOrder, scopes: Collection<int, WorkOrderScopeItem>} $context */
    public function draft(array $context, int $reportId, int $version): ServiceReport
    {
        ['workOrder' => $workOrder, 'scopes' => $scopes] = $context;
        $this->assertBaseline($workOrder, $scopes);
        $report = $workOrder->reports()->orderBy('id')->lockForUpdate()->findOrFail($reportId);
        if ($report->lock_version !== $version) {
            $this->commands->conflict('expected_report_version', 'REPORT_VERSION_CONFLICT');
        }
        $scope = $scopes->find($report->scope_item_id);
        if (! $scope || $report->revision_no < 1) {
            $this->commands->conflict('report_id', 'REPORT_M1_BASELINE_REQUIRED');
        }
        if (! in_array($workOrder->status, ['in_progress', 'rework', 'pending_review'], true)) {
            $this->commands->conflict('work_order_id', 'WORK_ORDER_STATE_CONFLICT');
        }
        if ($scope->disposition !== 'active') {
            $this->commands->conflict('scope_item_id', 'SCOPE_STATE_CONFLICT');
        }
        if ($report->review_status !== 'draft' || $report->submitted_at !== null || $report->reviewed_at !== null
            || $report->reviewed_by !== null || $report->review_notes !== null) {
            $this->commands->conflict('report_id', 'REPORT_STATE_CONFLICT');
        }
        $head = $scope->reports()->orderByDesc('revision_no')->orderByDesc('id')->lockForUpdate()->first();
        if (! $head || ! $head->is($report)) {
            $this->commands->conflict('report_id', 'REPORT_REVISION_CONFLICT');
        }

        return $report;
    }
}
