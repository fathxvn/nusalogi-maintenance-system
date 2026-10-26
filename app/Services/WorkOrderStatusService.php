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
        ) {
            $wo = WorkOrder::query()
                ->lockForUpdate()
                ->findOrFail($workOrderId);

            $allowed = self::TRANSITIONS[$wo->status] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Perubahan status tidak diperbolehkan.',
                ]);
            }

            // Aturan sementara selama role belum diimplementasikan.
            if ($toStatus === 'assigned') {
                if ((int) $wo->created_by !== (int) $actor->id) {
                    throw new AuthorizationException(
                        'Hanya pembuat WO yang boleh melakukan penugasan.'
                    );
                }

                if (! $wo->technicians()->exists()) {
                    throw ValidationException::withMessages([
                        'technicians' => 'WO belum memiliki teknisi.',
                    ]);
                }
            }

            if ($toStatus === 'in_progress') {
                $isAssigned = $wo->technicians()
                    ->where('users.id', $actor->id)
                    ->exists();

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
        });
    }
}
