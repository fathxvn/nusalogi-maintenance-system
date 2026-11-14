<?php

namespace App\Http\Controllers;

use App\Models\BastItem;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\M1CommandService;
use App\Services\WorkOrderLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderLifecycleController extends Controller
{
    public function show(Request $request, WorkOrder $workOrder, M1CommandService $commands): Response
    {
        $actor = $commands->authorize($request->user(), User::ROLES);
        $wo = WorkOrder::query()->visibleTo($actor)->with(['site.customer', 'scopeItems.reports'])->findOrFail($workOrder->id);
        $baseline = in_array($wo->origin_kind, ['ticket', 'manual'], true) && filled($wo->acceptance_context_ref)
            && $wo->scopeItems->contains(fn ($scope): bool => $scope->is_required && $scope->disposition === 'active');
        $coordinator = $actor->hasRole('koordinator');
        $member = $actor->hasRole('teknisi');
        $actions = [];
        foreach (['hold', 'resume', 'cancel', 'context', 'close'] as $action) {
            $allowed = $baseline && match ($action) {
                'hold' => ($coordinator || $member) && in_array($wo->status, ['assigned', 'in_progress', 'rework'], true),
                'resume' => ($coordinator || $member) && $wo->status === 'on_hold',
                'cancel' => $coordinator && in_array($wo->status, ['pending', 'assigned', 'on_hold'], true),
                'context' => $coordinator && ! in_array($wo->status, ['closed', 'cancelled'], true),
                'close' => $coordinator && $wo->status === 'awaiting_acceptance',
            };
            $actions[$action] = $allowed ? ['url' => $action === 'close' ? route('work-orders.lifecycle.close', $wo)
                : route('work-orders.lifecycle.change', [$wo, $action]), 'command_key' => (string) Str::uuid()] : null;
        }
        $scopeIds = $wo->scopeItems->modelKeys();
        $accepted = BastItem::query()->whereHas('version', fn ($query) => $query->where('status', 'accepted'))
            ->whereHas('reportRevision', fn ($query) => $query->whereIn('scope_item_id', $scopeIds))->pluck('report_revision_id')->all();

        return Inertia::render('work-orders/lifecycle', [
            'workOrder' => ['id' => $wo->id, 'number' => $wo->wo_number, 'title' => $wo->title, 'status' => $wo->status,
                'lock_version' => $wo->lock_version, 'context' => $wo->acceptance_context_ref,
                'closed_at' => $wo->closed_at?->toISOString(), 'customer' => $wo->site?->customer?->name, 'site' => $wo->site?->site_name],
            'scopes' => $wo->scopeItems->map(function ($scope) use ($accepted): array {
                $head = $scope->reports->sortByDesc('revision_no')->first();

                return ['id' => $scope->id, 'description' => $scope->description, 'is_required' => $scope->is_required, 'disposition' => $scope->disposition,
                    'head_revision' => $head?->revision_no, 'head_status' => $head?->review_status,
                    'accepted' => $head && $head->review_status === 'approved' && in_array($head->id, $accepted, true)];
            })->all(),
            'history' => $wo->updates()->with('changedBy:id,name')->orderByDesc('id')->limit(30)->get()->map(fn ($update): array => [
                'id' => $update->id, 'from' => $update->from_status, 'to' => $update->to_status, 'notes' => $update->notes,
                'actor' => $update->changedBy?->name, 'at' => $update->created_at?->toISOString(),
            ])->all(),
            'closureEvidence' => collect(OperationHistory::query()->where('subject_type', 'work_order')->where('subject_id', $wo->id)
                ->where('action', 'work_order.close')->orderByDesc('id')->first()?->facts['closure_evidence'] ?? [])
                ->map(fn (array $proof): array => ['scope_item_id' => $proof['scope_item_id']])->all(),
            'actions' => $actions, 'unavailableReason' => $baseline ? null : 'WO historis memerlukan verifikasi origin, konteks, dan scope sebelum memakai lifecycle M1.',
            'links' => ['reports' => route('work-orders.reports.index', $wo), 'visits' => route('work-orders.visits.index', $wo),
                'acceptance' => route('work-orders.acceptance.index', $wo), 'refresh' => route('work-orders.lifecycle.show', $wo)],
        ]);
    }

    public function change(Request $request, WorkOrder $workOrder, string $action, WorkOrderLifecycleService $service): RedirectResponse
    {
        $wo = WorkOrder::query()->visibleTo($request->user())->findOrFail($workOrder->id);
        $service->change($request->user(), $wo->id, $action, $request->only(['command_key', 'expected_work_order_version', 'reason', 'acceptance_context_ref']));

        return redirect()->route('work-orders.lifecycle.show', $wo)->with('success', 'Perubahan lifecycle WO tercatat.');
    }

    public function close(Request $request, WorkOrder $workOrder, WorkOrderLifecycleService $service): RedirectResponse
    {
        $wo = WorkOrder::query()->visibleTo($request->user())->findOrFail($workOrder->id);
        $service->close($request->user(), $wo->id, $request->only(['command_key', 'expected_work_order_version', 'reason', 'closure_mode', 'exception', 'exception_approved']));

        return redirect()->route('work-orders.lifecycle.show', $wo)->with('success', 'WO ditutup berdasarkan acceptance seluruh scope wajib.');
    }
}
