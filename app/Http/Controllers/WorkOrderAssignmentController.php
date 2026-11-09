<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderAssignmentController extends Controller
{
    public function edit(WorkOrder $workOrder): Response
    {
        abort_unless($workOrder->status === 'pending', 404);

        $workOrder->load([
            'site:id,customer_id,site_name',
            'site.customer:id,name',
        ]);

        $technicians = User::query()
            ->where('role', 'teknisi')
            ->where('is_active', true)
            ->where('approval_status', 'approved')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        return Inertia::render('work-orders/assign', [
            'workOrder' => [
                'id' => $workOrder->id,
                'number' => $workOrder->wo_number,
                'title' => $workOrder->title,
                'status' => $workOrder->status,
                'customer' => $workOrder->site?->customer?->name,
                'site' => $workOrder->site?->site_name,
            ],
            'technicians' => $technicians,
            'links' => [
                'index' => route('work-orders.index'),
                'update' => route(
                    'work-orders.assignment.update',
                    $workOrder
                ),
            ],
        ]);
    }

    public function update(
        Request $request,
        WorkOrder $workOrder,
        WorkOrderStatusService $statusService
    ): RedirectResponse {
        $validated = $request->validate([
            'technician_ids' => [
                'required',
                'array',
                'list',
                'min:1',
            ],
            'technician_ids.*' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('users', 'id')
                    ->where('role', 'teknisi')
                    ->where('is_active', true)
                    ->where('approval_status', 'approved'),
            ],
        ]);

        $ids = array_map('intval', $validated['technician_ids']);

        DB::transaction(function () use (
            $workOrder,
            $ids,
            $request,
            $statusService
        ): void {
            $lockedWorkOrder = WorkOrder::query()
                ->lockForUpdate()
                ->findOrFail($workOrder->id);

            if ($lockedWorkOrder->status !== 'pending') {
                throw ValidationException::withMessages([
                    'technician_ids' => 'Work Order ini sudah tidak berstatus pending.',
                ]);
            }

            $coordinator = User::query()->lockForUpdate()->find($request->user()->id);

            abort_unless(
                $coordinator && $coordinator->hasRole('koordinator') && $coordinator->isActive() && $coordinator->isApproved(),
                403,
                'Hanya Koordinator aktif yang sudah disetujui boleh melakukan penugasan.'
            );

            $technicians = User::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'role', 'is_active', 'approval_status']);

            $invalidTechnician = $technicians->count() !== count($ids)
                || $technicians->contains(
                    fn (User $user): bool => ! $user->hasRole('teknisi')
                        || ! $user->isActive()
                        || ! $user->isApproved()
                );

            if ($invalidTechnician) {
                throw ValidationException::withMessages([
                    'technician_ids' => 'Pilih teknisi yang aktif dan sudah disetujui.',
                ]);
            }

            $lockedWorkOrder->technicians()->syncWithPivotValues(
                $ids,
                ['assigned_at' => now()]
            );

            $statusService->changeStatus(
                $lockedWorkOrder->id,
                $coordinator,
                'assigned',
                'Penugasan teknisi oleh Koordinator.'
            );
        }, 3);

        return redirect()
            ->route('work-orders.index')
            ->with('success', 'Teknisi berhasil ditugaskan.');
    }
}
