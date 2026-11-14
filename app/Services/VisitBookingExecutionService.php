<?php

namespace App\Services;

use App\Exceptions\VisitResourceSetChanged;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VisitBookingExecutionService
{
    public function __construct(private M1CommandService $commands) {}

    /** @param array<string, mixed> $input */
    public function start(User $actor, int $bookingId, array $input): VisitBooking
    {
        return $this->execute($actor, $bookingId, 'start', $input);
    }

    /** @param array<string, mixed> $input */
    public function complete(User $actor, int $bookingId, array $input): VisitBooking
    {
        return $this->execute($actor, $bookingId, 'complete', $input);
    }

    /** @param array<string, mixed> $input */
    private function execute(
        User $actor,
        int $bookingId,
        string $action,
        array $input,
    ): VisitBooking {
        $this->commands->authorize($actor, ['teknisi']);

        $data = Validator::make($input, [
            'command_key' => ['bail', 'required', 'uuid'],
            'expected_visit_version' => ['required', 'integer', 'min:0'],
            'expected_booking_status' => [
                'required',
                Rule::in(['confirmed', 'in_progress']),
            ],
        ])->validate();

        $key = strtolower($data['command_key']);
        unset($data['command_key']);

        $data['expected_visit_version'] = (int) $data['expected_visit_version'];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $discoveredBooking = VisitBooking::query()->findOrFail($bookingId);
            $discoveredVisit = WorkVisit::query()
                ->findOrFail($discoveredBooking->visit_id);

            $resourceIds = VisitBooking::query()
                ->where('visit_id', $discoveredVisit->id)
                ->pluck('technician_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $resourceIds[] = (int) $actor->id;
            $resourceIds = array_values(array_unique($resourceIds));
            sort($resourceIds);

            try {
                $history = $this->commands->run(
                    $actor,
                    $key,
                    'visit.booking.'.$action,
                    ['booking_id' => $bookingId, ...$data],
                    ['teknisi'],
                    function () use (
                        $actor,
                        $bookingId,
                        $discoveredVisit,
                        $resourceIds,
                    ): array {
                        $workOrder = WorkOrder::query()
                            ->lockForUpdate()
                            ->findOrFail($discoveredVisit->work_order_id);

                        // Lock seluruh teknisi secara berurutan sebelum
                        // M1CommandService mengambil ulang lock actor.
                        foreach ($resourceIds as $resourceId) {
                            $resource = User::query()
                                ->lockForUpdate()
                                ->find($resourceId);

                            if (! $resource) {
                                $this->commands->conflict(
                                    'booking_id',
                                    'BOOKING_RESOURCE_ANOMALY',
                                );
                            }
                        }

                        $member = DB::table('work_order_technicians')
                            ->where('work_order_id', $workOrder->id)
                            ->where('user_id', $actor->id)
                            ->lockForUpdate()
                            ->first();

                        $visit = WorkVisit::query()
                            ->lockForUpdate()
                            ->findOrFail($discoveredVisit->id);

                        if ((int) $visit->work_order_id !== (int) $workOrder->id) {
                            throw new VisitResourceSetChanged;
                        }

                        $bookings = $visit->bookings()
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();

                        $currentIds = $bookings
                            ->pluck('technician_id')
                            ->map(fn ($id): int => (int) $id)
                            ->all();

                        $booking = $bookings->firstWhere('id', $bookingId);

                        if (array_diff($currentIds, $resourceIds) || ! $booking) {
                            throw new VisitResourceSetChanged;
                        }

                        if (! $member
                            || (int) $booking->technician_id !== (int) $actor->id) {
                            throw new AuthorizationException(
                                'Hanya teknisi peserta yang boleh menjalankan booking sendiri.',
                            );
                        }

                        return compact(
                            'workOrder',
                            'visit',
                            'bookings',
                            'booking',
                        );
                    },
                    function (array $context, User $current) use (
                        $action,
                        $data,
                    ): array {
                        [
                            'workOrder' => $workOrder,
                            'visit' => $visit,
                            'bookings' => $bookings,
                            'booking' => $booking,
                        ] = $context;

                        if ($visit->lock_version !== $data['expected_visit_version']) {
                            $this->commands->conflict(
                                'expected_visit_version',
                                'VISIT_VERSION_CONFLICT',
                            );
                        }

                        $requiredStatus = $action === 'start'
                            ? 'confirmed'
                            : 'in_progress';

                        if ($booking->status !== $data['expected_booking_status']
                            || $booking->status !== $requiredStatus) {
                            $this->commands->conflict(
                                'expected_booking_status',
                                'BOOKING_STATE_CONFLICT',
                            );
                        }

                        $requiredScopes = $workOrder->scopeItems()
                            ->where('disposition', 'active')
                            ->where('is_required', true)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();

                        if (! in_array($workOrder->origin_kind, ['ticket', 'manual'], true)
                            || blank($workOrder->acceptance_context_ref)
                            || $requiredScopes->isEmpty()) {
                            $this->commands->conflict(
                                'work_order_id',
                                'WORK_ORDER_M1_BASELINE_REQUIRED',
                            );
                        }

                        $allowedStates = $action === 'start'
                            ? ['assigned', 'in_progress', 'rework']
                            : ['in_progress'];

                        if (! in_array($workOrder->status, $allowedStates, true)) {
                            $this->commands->conflict(
                                'work_order_id',
                                'WORK_ORDER_STATE_CONFLICT',
                            );
                        }

                        $this->assertVisitState($visit, $bookings);

                        $actualTime = now('UTC')->startOfSecond();
                        $visitStatusBefore = $visit->status;
                        $bookingStatusBefore = $booking->status;
                        $workOrderStatusBefore = $workOrder->status;

                        if ($action === 'start') {
                            $otherRunning = VisitBooking::query()
                                ->where('technician_id', $current->id)
                                ->where('id', '!=', $booking->id)
                                ->where('status', 'in_progress')
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->first();

                            if ($otherRunning) {
                                $this->commands->conflict(
                                    'booking_id',
                                    'TECHNICIAN_ALREADY_WORKING',
                                );
                            }

                            $booking->update([
                                'status' => 'in_progress',
                                'actual_start_at' => $actualTime->format('Y-m-d H:i:s.u'),
                            ]);

                            if ($workOrder->status !== 'in_progress') {
                                $workOrder->forceFill([
                                    'status' => 'in_progress',
                                    'lock_version' => (int) $workOrder->lock_version + 1,
                                ])->save();

                                $workOrder->updates()->create([
                                    'changed_by' => $current->id,
                                    'from_status' => $workOrderStatusBefore,
                                    'to_status' => 'in_progress',
                                    'notes' => 'Mulai booking kunjungan '.$visit->visit_no.'.',
                                ]);
                            }
                        } else {
                            if ($actualTime->lessThan($booking->actual_start_at)) {
                                $this->commands->conflict(
                                    'booking_id',
                                    'BOOKING_ACTUAL_TIME_CONFLICT',
                                );
                            }

                            $booking->update([
                                'status' => 'completed',
                                'actual_end_at' => $actualTime->format('Y-m-d H:i:s.u'),
                            ]);
                        }

                        $hasActive = $bookings->contains(
                            fn (VisitBooking $participant): bool => in_array(
                                $participant->status,
                                ['confirmed', 'in_progress'],
                                true,
                            ),
                        );

                        $visit->update([
                            'status' => $hasActive ? 'in_progress' : 'completed',
                            'lock_version' => $visit->lock_version + 1,
                        ]);

                        return [
                            'subject_type' => 'visit_booking',
                            'subject_id' => $booking->id,
                            'from_state' => $bookingStatusBefore,
                            'to_state' => $booking->status,
                            'facts' => [
                                'work_order_id' => $workOrder->id,
                                'visit_id' => $visit->id,
                                'booking_id' => $booking->id,
                                'technician_id' => $current->id,
                                'participant_ids' => $bookings->pluck('technician_id')->all(),
                                'expected_visit_version' => $data['expected_visit_version'],
                                'expected_booking_status' => $data['expected_booking_status'],
                                'lock_version' => $visit->lock_version,
                                'visit_status_before' => $visitStatusBefore,
                                'visit_status_after' => $visit->status,
                                'work_order_status_before' => $workOrderStatusBefore,
                                'work_order_status_after' => $workOrder->status,
                                'actual_start_at' => $booking->actual_start_at?->format('Y-m-d H:i:s.u'),
                                'actual_end_at' => $booking->actual_end_at?->format('Y-m-d H:i:s.u'),
                            ],
                        ];
                    },
                );

                return VisitBooking::query()->findOrFail($history->subject_id);
            } catch (VisitResourceSetChanged) {
                continue;
            }
        }

        $this->commands->conflict('booking_id', 'VISIT_RESOURCES_CHANGED');
    }

    /** @param Collection<int, VisitBooking> $bookings */
    private function assertVisitState(WorkVisit $visit, Collection $bookings): void
    {
        if (! in_array($visit->status, ['confirmed', 'in_progress'], true)) {
            $this->commands->conflict('visit_id', 'VISIT_STATE_CONFLICT');
        }

        $hasStarted = false;
        $hasActive = false;

        foreach ($bookings as $booking) {
            $valid = match ($booking->status) {
                'confirmed', 'cancelled' => $booking->actual_start_at === null
                    && $booking->actual_end_at === null,
                'in_progress' => $booking->actual_start_at !== null
                    && $booking->actual_end_at === null,
                'completed' => $booking->actual_start_at !== null
                    && $booking->actual_end_at !== null
                    && ! $booking->actual_end_at->lessThan($booking->actual_start_at),
                default => false,
            };

            if (! $valid) {
                $this->commands->conflict(
                    'visit_id',
                    'VISIT_BOOKING_STATE_ANOMALY',
                );
            }

            $hasStarted = $hasStarted || $booking->actual_start_at !== null;
            $hasActive = $hasActive || in_array(
                $booking->status,
                ['confirmed', 'in_progress'],
                true,
            );
        }

        if (! $hasActive
            || ($visit->status === 'confirmed' && $hasStarted)
            || ($visit->status === 'in_progress' && ! $hasStarted)
            || $visit->successorVisit()->lockForUpdate()->first() !== null) {
            $this->commands->conflict(
                'visit_id',
                'VISIT_BOOKING_STATE_ANOMALY',
            );
        }
    }
}
