<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkOrderStatusService
{
    private const TRANSITIONS = [
        'pending' => ['assigned'],
        'assigned' => ['in_progress'],
    ];

    public function changeStatus(
        int $workOrderId,
        User $actor,
        string $toStatus,
        ?string $notes = null
    ): WorkOrder {
        return DB::transaction(function () use (
            $workOrderId,
            $actor,
            $toStatus,
            $notes
        ): WorkOrder {
            $wo = WorkOrder::query()
                ->lockForUpdate()
                ->findOrFail($workOrderId);

            $allowed = self::TRANSITIONS[$wo->status] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Perubahan status tidak diperbolehkan.',
                ]);
            }

            if ($toStatus === 'assigned') {
                $coordinator = User::query()->lockForUpdate()->find($actor->id);

                if (! $coordinator || ! $coordinator->hasRole('koordinator') || ! $coordinator->isActive() || ! $coordinator->isApproved()) {
                    throw new AuthorizationException(
                        'Hanya Koordinator yang boleh melakukan penugasan.'
                    );
                }

                $technicians = $wo->technicians()->orderBy('users.id')->lockForUpdate()
                    ->get(['users.id', 'role', 'is_active', 'approval_status']);

                if ($technicians->isEmpty()) {
                    throw ValidationException::withMessages([
                        'technicians' => 'WO belum memiliki teknisi.',
                    ]);
                }

                if ($technicians->contains(fn (User $technician): bool => ! $technician->hasRole('teknisi') || ! $technician->isActive() || ! $technician->isApproved()
                )) {
                    throw ValidationException::withMessages([
                        'technicians' => 'Pilih teknisi yang aktif dan sudah disetujui.',
                    ]);
                }
            }

            if ($toStatus === 'in_progress') {
                $technician = User::query()->lockForUpdate()->find($actor->id);
                $isAssigned = $technician && $technician->hasRole('teknisi')
                    && $technician->isActive() && $technician->isApproved()
                    && $wo->technicians()->where('users.id', $technician->id)->exists();

                if (! $isAssigned) {
                    throw new AuthorizationException(
                        'Pengguna bukan teknisi yang ditugaskan.'
                    );
                }
            }

            $fromStatus = $wo->status;

            $wo->status = $toStatus;
            $wo->save();

            $wo->updates()->create([
                'changed_by' => $actor->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $notes,
            ]);

            return $wo->refresh();
        }, 3);
    }
}
