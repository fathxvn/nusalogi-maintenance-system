<?php

namespace App\Services;

use App\Models\ServiceReport;
use App\Models\User;
use App\Models\VisitBooking;
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

    /** @return list<int> */
    public function discoverResources(int $workOrderId, User $actor): array
    {
        $resources = VisitBooking::query()->join('work_visits', 'work_visits.id', '=', 'visit_bookings.visit_id')
            ->where('work_visits.work_order_id', $workOrderId)->pluck('visit_bookings.technician_id')->all();
        $resources[] = $actor->id;
        $resources = array_values(array_unique(array_map('intval', $resources)));
        sort($resources);

        return $resources;
    }

    /** @param list<int> $resources
     * @return array{workOrder: WorkOrder, scopes: Collection<int, WorkOrderScopeItem>, bookings: Collection<int, VisitBooking>}
     */
    public function lockExecutionRoots(User $actor, int $workOrderId, int $reportId, array $resources): array
    {
        $context = $this->lockRoots($actor, $workOrderId, $reportId);
        User::query()->whereIn('id', $resources)->orderBy('id')->lockForUpdate()->get();
        $visits = $context['workOrder']->visits()->orderBy('id')->lockForUpdate()->get();
        $bookings = VisitBooking::query()->whereIn('visit_id', $visits->modelKeys())->orderBy('id')->lockForUpdate()->get();
        if (array_diff($bookings->pluck('technician_id')->map(fn ($id): int => (int) $id)->all(), $resources) !== []) {
            $this->commands->conflict('work_order_id', 'WORK_ORDER_PARTICIPANTS_CHANGED');
        }

        return [...$context, 'bookings' => $bookings];
    }

    /** @param array{workOrder: WorkOrder, scopes: Collection<int, WorkOrderScopeItem>} $context */
    public function head(array $context, int $reportId, int $version): ServiceReport
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
        if ($scope->disposition !== 'active') {
            $this->commands->conflict('scope_item_id', 'SCOPE_STATE_CONFLICT');
        }
        $head = $scope->reports()->orderByDesc('revision_no')->orderByDesc('id')->lockForUpdate()->first();
        if (! $head || ! $head->is($report)) {
            $this->commands->conflict('report_id', 'REPORT_REVISION_CONFLICT');
        }

        return $report;
    }

    /** @param array{workOrder: WorkOrder, scopes: Collection<int, WorkOrderScopeItem>} $context */
    public function draft(array $context, int $reportId, int $version): ServiceReport
    {
        $report = $this->head($context, $reportId, $version);
        if (! in_array($context['workOrder']->status, ['in_progress', 'rework', 'pending_review'], true)) {
            $this->commands->conflict('work_order_id', 'WORK_ORDER_STATE_CONFLICT');
        }
        if ($report->review_status !== 'draft' || $report->submitted_at !== null || $report->reviewed_at !== null
            || $report->reviewed_by !== null || $report->review_notes !== null) {
            $this->commands->conflict('report_id', 'REPORT_STATE_CONFLICT');
        }

        return $report;
    }
}
