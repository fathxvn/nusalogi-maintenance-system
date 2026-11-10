<?php

namespace App\Services;

use App\Exceptions\VisitResourceSetChanged;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CancelVisitBookingService
{
    public function __construct(private M1CommandService $commands) {}

    /** @param array<string, mixed> $input */
    public function cancel(
        User $actor,
        int $bookingId,
        array $input,
    ): VisitBooking {
        $this->commands->authorize($actor, ['koordinator']);

        $data = Validator::make($input, [
            'command_key' => ['bail', 'required', 'uuid'],
            'expected_visit_version' => ['required', 'integer', 'min:0'],
            'expected_booking_status' => [
                'required',
                Rule::in([
                    'confirmed',
                    'in_progress',
                    'completed',
                    'cancelled',
                ]),
            ],
            'reason' => [
                'bail',
                'required',
                'string',
                'max:10000',
                'encoding:UTF-8',
            ],
        ])->validate();

        $key = strtolower($data['command_key']);
        unset($data['command_key']);

        $data['expected_visit_version'] = (int) $data['expected_visit_version'];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // Discovery only determines the candidate lock set.
            $discoveredBooking = VisitBooking::query()
                ->findOrFail($bookingId);

            $discoveredVisit = WorkVisit::query()
                ->findOrFail($discoveredBooking->visit_id);

            $participantIds = VisitBooking::query()
                ->where('visit_id', $discoveredVisit->id)
                ->pluck('technician_id')
                ->all();

            $resourceIds = array_values(array_unique(array_map(
                'intval',
                [
                    ...$participantIds,
                    $discoveredBooking->technician_id,
                ],
            )));
            sort($resourceIds);

            try {
                $history = $this->commands->run(
                    $actor,
                    $key,
                    'visit.booking.cancel',
                    ['booking_id' => $bookingId, ...$data],
                    ['koordinator'],
                    fn (): WorkOrder => WorkOrder::query()
                        ->lockForUpdate()
                        ->findOrFail($discoveredVisit->work_order_id),
                    function (WorkOrder $workOrder, User $current) use (
                        $bookingId,
                        $discoveredVisit,
                        $resourceIds,
                        $data,
                    ): array {
                        // Coordinator is already locked by M1CommandService.
                        $resources = [];

                        foreach ($resourceIds as $resourceId) {
                            $resources[$resourceId] = User::query()
                                ->lockForUpdate()
                                ->find($resourceId);

                            if (! $resources[$resourceId]) {
                                $this->commands->conflict(
                                    'booking_id',
                                    'BOOKING_RESOURCE_ANOMALY',
                                );
                            }
                        }

                        $memberIds = DB::table('work_order_technicians')
                            ->where('work_order_id', $workOrder->id)
                            ->whereIn('user_id', $resourceIds)
                            ->orderBy('user_id')
                            ->lockForUpdate()
                            ->pluck('user_id')
                            ->map(fn ($id): int => (int) $id)
                            ->all();

                        $visit = WorkVisit::query()
                            ->lockForUpdate()
                            ->findOrFail($discoveredVisit->id);

                        if ((int) $visit->work_order_id !== (int) $workOrder->id) {
                            throw new VisitResourceSetChanged;
                        }

                        $bookings = VisitBooking::query()
                            ->where('visit_id', $visit->id)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();

                        $currentParticipantIds = $bookings
                            ->pluck('technician_id')
                            ->map(fn ($id): int => (int) $id)
                            ->all();

                        if (array_diff($currentParticipantIds, $resourceIds)) {
                            throw new VisitResourceSetChanged;
                        }

                        $booking = $bookings->firstWhere('id', $bookingId);

                        if (! $booking) {
                            throw new VisitResourceSetChanged;
                        }

                        if (! in_array(
                            $workOrder->status,
                            [
                                'assigned',
                                'in_progress',
                                'on_hold',
                                'verification',
                                'pending_review',
                                'awaiting_acceptance',
                                'rework',
                            ],
                            true,
                        )) {
                            $this->commands->conflict(
                                'work_order_id',
                                'WORK_ORDER_STATE_CONFLICT',
                            );
                        }

                        if ($visit->lock_version !== $data['expected_visit_version']) {
                            $this->commands->conflict(
                                'expected_visit_version',
                                'VISIT_VERSION_CONFLICT',
                            );
                        }

                        if ($booking->status !== $data['expected_booking_status']) {
                            $this->commands->conflict(
                                'expected_booking_status',
                                'BOOKING_STATE_CONFLICT',
                            );
                        }

                        if ($booking->status !== 'confirmed') {
                            $this->commands->conflict(
                                'booking_id',
                                'BOOKING_CANCELLATION_NOT_ALLOWED',
                            );
                        }

                        if (! in_array(
                            (int) $booking->technician_id,
                            $memberIds,
                            true,
                        )) {
                            $this->commands->conflict(
                                'booking_id',
                                'BOOKING_MEMBERSHIP_ANOMALY',
                            );
                        }

                        $nextStatus = $this->nextVisitStatus(
                            $visit,
                            $bookings,
                            $booking,
                        );

                        $previousVisitStatus = $visit->status;
                        $technician = $resources[$booking->technician_id];

                        // Cancellation may release an unstarted reservation
                        // even when the technician is no longer eligible.
                        $eligibleAtCancellation = $technician->hasRole('teknisi')
                            && $technician->isActive()
                            && $technician->isApproved();

                        $booking->update([
                            'status' => 'cancelled',
                            'ended_reason' => $data['reason'],
                        ]);

                        $visit->update([
                            'status' => $nextStatus,
                            'lock_version' => $visit->lock_version + 1,
                        ]);

                        return [
                            'subject_type' => 'visit_booking',
                            'subject_id' => $booking->id,
                            'from_state' => 'confirmed',
                            'to_state' => 'cancelled',
                            'reason' => $data['reason'],
                            'facts' => [
                                'work_order_id' => $workOrder->id,
                                'visit_id' => $visit->id,
                                'booking_id' => $booking->id,
                                'technician_id' => $booking->technician_id,
                                'participant_ids' => $currentParticipantIds,
                                'visit_status_before' => $previousVisitStatus,
                                'visit_status_after' => $visit->status,
                                'expected_visit_version' => $data['expected_visit_version'],
                                'expected_booking_status' => $data['expected_booking_status'],
                                'lock_version' => $visit->lock_version,
                                'technician_eligible_at_cancel' => $eligibleAtCancellation,
                            ],
                        ];
                    },
                );

                return VisitBooking::query()->findOrFail($history->subject_id);
            } catch (VisitResourceSetChanged) {
                // Rollback has completed. Discover the full set again.
                continue;
            }
        }

        $this->commands->conflict(
            'booking_id',
            'VISIT_RESOURCES_CHANGED',
        );
    }

    /** @param Collection<int, VisitBooking> $bookings */
    private function nextVisitStatus(
        WorkVisit $visit,
        Collection $bookings,
        VisitBooking $target,
    ): string {
        if (! in_array($visit->status, ['confirmed', 'in_progress'], true)) {
            $this->commands->conflict(
                'visit_id',
                'VISIT_STATE_CONFLICT',
            );
        }

        $hasStarted = false;
        $hasCompleted = false;
        $remainingActive = 0;

        foreach ($bookings as $booking) {
            $valid = match ($booking->status) {
                'confirmed', 'cancelled' => $booking->actual_start_at === null
                    && $booking->actual_end_at === null,
                'in_progress' => $booking->actual_start_at !== null
                    && $booking->actual_end_at === null,
                'completed' => $booking->actual_start_at !== null
                    && $booking->actual_end_at !== null
                    && ! $booking->actual_end_at->lessThan(
                        $booking->actual_start_at,
                    ),
                default => false,
            };

            if (! $valid) {
                $this->commands->conflict(
                    'visit_id',
                    'VISIT_BOOKING_STATE_ANOMALY',
                );
            }

            $hasStarted = $hasStarted || $booking->actual_start_at !== null;
            $hasCompleted = $hasCompleted || $booking->status === 'completed';

            if ($booking->id !== $target->id
                && in_array(
                    $booking->status,
                    ['confirmed', 'in_progress'],
                    true,
                )) {
                $remainingActive++;
            }
        }

        if (($visit->status === 'confirmed' && $hasStarted)
            || ($visit->status === 'in_progress' && ! $hasStarted)) {
            $this->commands->conflict(
                'visit_id',
                'VISIT_BOOKING_STATE_ANOMALY',
            );
        }

        if ($remainingActive > 0) {
            return $hasStarted ? 'in_progress' : 'confirmed';
        }

        return $hasCompleted ? 'completed' : 'cancelled';
    }
}
