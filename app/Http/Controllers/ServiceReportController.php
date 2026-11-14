<?php

namespace App\Http\Controllers;

use App\Models\BastItem;
use App\Models\MaterialUsage;
use App\Models\OperationHistory;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\M1CommandService;
use App\Services\MaterialUsageService;
use App\Services\ReportCorrectionService;
use App\Services\ServiceReportDraftService;
use App\Services\ServiceReportWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServiceReportController extends Controller
{
    public function index(Request $request, WorkOrder $workOrder, M1CommandService $commands): Response
    {
        $actor = $commands->authorize($request->user(), User::ROLES);
        $wo = $this->visible($actor, $workOrder);
        $scopes = $wo->scopeItems()->with(['reports.creator:id,name'])->orderBy('scope_no')->get();
        $baseline = in_array($wo->origin_kind, ['ticket', 'manual'], true) && filled($wo->acceptance_context_ref)
            && $scopes->contains(fn (WorkOrderScopeItem $scope): bool => $scope->is_required && $scope->disposition === 'active');

        return Inertia::render('work-orders/reports/index', [
            'workOrder' => $this->summary($wo),
            'scopes' => $scopes->map(function (WorkOrderScopeItem $scope) use ($wo, $actor, $baseline): array {
                $revisions = $scope->reports->sortByDesc('revision_no')->values();
                $canCreate = $baseline && $actor->hasRole('teknisi') && $scope->disposition === 'active'
                    && in_array($wo->status, ['in_progress', 'rework'], true) && $revisions->isEmpty();

                return [...$scope->only(['id', 'scope_no', 'description', 'acceptance_criteria', 'is_required', 'disposition']),
                    'create' => $canCreate ? $this->action('work-orders.reports.store', [$wo, $scope]) : null,
                    'revisions' => $revisions->map(fn (ServiceReport $report): array => [
                        'id' => $report->id, 'revision_no' => $report->revision_no, 'status' => $report->review_status,
                        'author' => $report->creator->name, 'url' => route('work-orders.reports.show', [$wo, $report]),
                    ])->all()];
            })->all(),
            'legacyReports' => $wo->reports()->whereNull('scope_item_id')->orderByDesc('id')->get()->map(fn (ServiceReport $report): array => [
                'id' => $report->id, 'status' => $report->review_status, 'url' => route('work-orders.reports.show', [$wo, $report]),
            ])->all(),
            'links' => ['index' => route('work-orders.index'), 'refresh' => route('work-orders.reports.index', $wo), 'visits' => route('work-orders.visits.index', $wo), 'acceptance' => route('work-orders.acceptance.index', $wo), 'lifecycle' => route('work-orders.lifecycle.show', $wo)],
            'unavailableReason' => $baseline ? null : 'Laporan historis tetap dapat dibaca. Origin, konteks acceptance, dan scope wajib M1 harus diverifikasi sebelum memakai workflow baru.',
        ]);
    }

    public function show(Request $request, WorkOrder $workOrder, int $report, M1CommandService $commands): Response
    {
        $actor = $commands->authorize($request->user(), User::ROLES);
        $wo = $this->visible($actor, $workOrder);
        $target = $wo->reports()->with(['creator:id,name', 'reviewer:id,name', 'scopeItem', 'photos.evidenceFile'])->findOrFail($report);
        $revisions = $target->scope_item_id === null ? collect() : $wo->reports()->where('scope_item_id', $target->scope_item_id)->orderByDesc('revision_no')->get();
        abort_if($target->scopeItem && (int) $target->scopeItem->work_order_id !== (int) $wo->id, 404);
        $baseline = in_array($wo->origin_kind, ['ticket', 'manual'], true) && filled($wo->acceptance_context_ref)
            && $wo->scopeItems()->where('is_required', true)->where('disposition', 'active')->exists();
        $isHead = $baseline && $revisions->first()?->id === $target->id;
        $active = $target->scopeItem?->disposition === 'active';
        $draft = $isHead && $active && $target->review_status === 'draft' && $target->submitted_at === null;
        $author = $actor->hasRole('teknisi') && (int) $target->created_by === (int) $actor->id;
        $editable = $author && $draft && in_array($wo->status, ['in_progress', 'rework', 'pending_review'], true);
        $reviewable = $actor->hasRole('koordinator') && $isHead && $active && $target->review_status === 'submitted'
            && (int) $target->created_by !== (int) $actor->id && in_array($wo->status, ['in_progress', 'pending_review', 'rework'], true);
        $base = [$wo, $target];

        return Inertia::render('work-orders/reports/show', [
            'workOrder' => $this->summary($wo),
            'report' => [...$target->only(['id', 'revision_no', 'previous_revision_id', 'review_status', 'diagnosis', 'action_taken', 'result',
                'recommendation', 'checklist_snapshot', 'asset_results', 'usage_snapshot', 'review_notes', 'lock_version']),
                'revision_category' => OperationHistory::query()->where('action', 'report.review')->where('subject_type', 'service_report')->where('subject_id', $target->id)->orderByDesc('id')->first()?->facts['category'] ?? null,
                'author' => $target->creator->name, 'reviewer' => $target->reviewer?->name,
                'submitted_at' => $target->submitted_at?->toISOString(), 'reviewed_at' => $target->reviewed_at?->toISOString(),
                'is_head' => $isHead, 'legacy' => $target->scope_item_id === null,
                'scope' => $target->scopeItem?->only(['id', 'scope_no', 'description', 'acceptance_criteria']),
                'photos' => $target->photos->map(fn ($photo): array => [
                    'id' => $photo->id, 'phase' => $photo->phase, 'caption' => $photo->caption,
                    'name' => $photo->evidenceFile?->original_name ?? 'Evidence historis belum diverifikasi',
                    'download_url' => $photo->evidenceFile?->state === 'ready' ? route('work-orders.reports.evidence.show', [$wo, $target, $photo]) : null,
                ])->all()],
            'revisions' => $revisions->map(fn (ServiceReport $revision): array => ['id' => $revision->id,
                'revision_no' => $revision->revision_no, 'status' => $revision->review_status,
                'url' => route('work-orders.reports.show', [$wo, $revision])])->all(),
            'materials' => $target->scope_item_id === null ? [] : MaterialUsage::query()->where('scope_item_id', $target->scope_item_id)->orderBy('id')->get()
                ->map(fn (MaterialUsage $usage): array => $usage->only(['id', 'item_description', 'quantity', 'unit_code', 'movement_kind', 'adjusts_usage_id', 'supply_source', 'disposition', 'reason']))->all(),
            'assets' => $wo->assets()->orderBy('assets.id')->get(['assets.id', 'asset_code'])->map->only(['id', 'asset_code'])->all(),
            'correctionAuthors' => $actor->hasRole('koordinator') ? $wo->technicians()->where('role', 'teknisi')->where('is_active', true)->where('approval_status', 'approved')->orderBy('users.id')->get(['users.id', 'name'])->map->only(['id', 'name'])->all() : [],
            'actions' => [
                'correction' => $actor->hasRole('koordinator') && $isHead && $active && in_array($target->review_status, ['approved', 'needs_revision'], true)
                    && in_array($wo->status, ['in_progress', 'pending_review', 'awaiting_acceptance', 'rework'], true)
                    && ! BastItem::query()->whereHas('reportRevision', fn ($query) => $query->where('scope_item_id', $target->scope_item_id))->whereHas('version', fn ($query) => $query->where('status', 'accepted'))->exists()
                    ? $this->action('work-orders.reports.correction', $base) : null,
                'update' => $editable ? $this->action('work-orders.reports.update', $base) : null,
                'submit' => $editable && $wo->status !== 'rework' ? $this->action('work-orders.reports.submit', $base) : null,
                'review' => $reviewable ? $this->action('work-orders.reports.review', $base) : null,
                'revise' => $author && $isHead && $active && $target->review_status === 'needs_revision'
                    && in_array($wo->status, ['in_progress', 'pending_review', 'rework'], true) ? $this->action('work-orders.reports.revise', $base) : null,
                'evidence' => $editable ? [...$this->action('work-orders.reports.evidence.store', $base), 'upload_key' => (string) Str::uuid()] : null,
                'material' => $actor->hasRole('teknisi') && $draft && in_array($wo->status, ['in_progress', 'pending_review', 'rework'], true)
                    ? $this->action('work-orders.reports.materials.store', $base) : null,
            ],
            'links' => ['index' => route('work-orders.reports.index', $wo), 'refresh' => route('work-orders.reports.show', $base)],
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder, int $scope, ServiceReportDraftService $service): RedirectResponse
    {
        $workOrder = $this->visible($request->user(), $workOrder);
        $report = $service->createDraft($request->user(), $workOrder->id, $scope, $request->only([
            'command_key', 'expected_work_order_version', 'diagnosis', 'action_taken', 'result', 'recommendation', 'checklist_snapshot', 'asset_results',
        ]));

        return redirect()->route('work-orders.reports.show', [$workOrder, $report])->with('success', 'Draft laporan dibuat.');
    }

    public function update(Request $request, WorkOrder $workOrder, int $report, ServiceReportDraftService $service): RedirectResponse
    {
        $workOrder = $this->visible($request->user(), $workOrder);
        $updated = $service->updateDraft($request->user(), $workOrder->id, $report, $request->only([
            'command_key', 'expected_report_version', 'diagnosis', 'action_taken', 'result', 'recommendation', 'checklist_snapshot', 'asset_results',
        ]));

        return redirect()->route('work-orders.reports.show', [$workOrder, $updated])->with('success', 'Draft laporan diperbarui.');
    }

    public function submit(Request $request, WorkOrder $workOrder, int $report, ServiceReportWorkflowService $service): RedirectResponse
    {
        $workOrder = $this->visible($request->user(), $workOrder);
        $service->submit($request->user(), $workOrder->id, $report, $request->only(['command_key', 'expected_report_version', 'expected_work_order_version']));

        return redirect()->route('work-orders.reports.show', [$workOrder, $report])->with('success', 'Laporan diajukan. Isi dan evidence revisi ini dibekukan.');
    }

    public function review(Request $request, WorkOrder $workOrder, int $report, ServiceReportWorkflowService $service): RedirectResponse
    {
        $workOrder = $this->visible($request->user(), $workOrder);
        $service->review($request->user(), $workOrder->id, $report, $request->only(['command_key', 'expected_report_version', 'expected_work_order_version', 'decision', 'category', 'reason']));

        return redirect()->route('work-orders.reports.show', [$workOrder, $report])->with('success', 'Keputusan review tersimpan.');
    }

    public function revise(Request $request, WorkOrder $workOrder, int $report, ServiceReportWorkflowService $service): RedirectResponse
    {
        $workOrder = $this->visible($request->user(), $workOrder);
        $next = $service->revise($request->user(), $workOrder->id, $report, $request->only(['command_key', 'expected_report_version', 'expected_work_order_version']));

        return redirect()->route('work-orders.reports.show', [$workOrder, $next])->with('success', 'Revisi baru dibuat; evidence revisi lama dipertahankan.');
    }

    public function material(Request $request, WorkOrder $workOrder, int $report, MaterialUsageService $service): RedirectResponse
    {
        $workOrder = $this->visible($request->user(), $workOrder);
        $service->record($request->user(), $workOrder->id, $report, $request->only(['command_key', 'expected_report_version', 'movement_kind', 'adjusts_usage_id',
            'item_description', 'unit_code', 'quantity', 'supply_source', 'disposition', 'reason']));

        return redirect()->route('work-orders.reports.show', [$workOrder, $report])->with('success', 'Movement material tercatat.');
    }

    public function correction(Request $request, WorkOrder $workOrder, int $report, ReportCorrectionService $service): RedirectResponse
    {
        $workOrder = $this->visible($request->user(), $workOrder);
        $next = $service->open($request->user(), $workOrder->id, $report, $request->only(['command_key', 'expected_report_version', 'expected_work_order_version', 'author_id', 'category', 'reason']));

        return redirect()->route('work-orders.reports.show', [$workOrder, $next])->with('success', 'Koreksi dibuka; paket BAST pending yang terdampak disupersede.');
    }

    private function visible(User $actor, WorkOrder $workOrder): WorkOrder
    {
        return WorkOrder::query()->visibleTo($actor)->with('site.customer')->findOrFail($workOrder->id);
    }

    private function summary(WorkOrder $wo): array
    {
        return ['id' => $wo->id, 'number' => $wo->wo_number, 'title' => $wo->title, 'status' => $wo->status,
            'customer' => $wo->site?->customer?->name, 'site' => $wo->site?->site_name, 'lock_version' => (int) $wo->lock_version];
    }

    private function action(string $route, array $parameters): array
    {
        return ['url' => route($route, $parameters), 'command_key' => (string) Str::uuid()];
    }
}
