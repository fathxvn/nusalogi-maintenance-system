<?php

namespace App\Services;

use App\Exceptions\VisitResourceSetChanged;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitSchedulingService
{
    public function __construct(private M1CommandService $commands) {}

    /** @param array<string, mixed> $input */
    public function createDraft(
        User $actor,
        int $workOrderId,
        array $input,
    ): WorkVisit {
        $this->commands->authorize($actor, ['koordinator']);

        $data = Validator::make($input, $this->scheduleRules())->validate();
        $key = strtolower($data['command_key']);
        unset($data['command_key']);
        $data['reason'] = $data['reason'] ?? null;
        $times = $this->scheduleTimes($data);

        $history = $this->commands->run(
            $actor,
            $key,
            'visit.create_draft',
            ['work_order_id' => $workOrderId, ...$data],
            ['koordinator'],
            function () use ($workOrderId): array {
                $workOrder = WorkOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($workOrderId);

                $scopes = $workOrder->scopeItems()
                    ->where('disposition', 'active')
                    ->where('is_required', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                return compact('workOrder', 'scopes');
            },
            function (array $context, User $current) use (
                $data,
                $times,
            ): array {
                $workOrder = $context['workOrder'];

                if (! in_array(
                    $workOrder->origin_kind,
                    ['ticket', 'manual'],
                    true,
                ) || blank($workOrder->acceptance_context_ref)
                    || $context['scopes']->isEmpty()) {
                    $this->commands->conflict(
                        'work_order_id',
                        'WORK_ORDER_M1_BASELINE_REQUIRED',
                    );
                }

                if (! in_array(
                    $workOrder->status,
                    ['pending', 'assigned', 'in_progress', 'on_hold', 'rework'],
                    true,
                )) {
                    $this->commands->conflict(
                        'work_order_id',
                        'WORK_ORDER_STATE_CONFLICT',
                    );
                }

                $latest = $workOrder->visits()
                    ->orderByDesc('visit_no')
                    ->lockForUpdate()
                    ->first();

                $visit = WorkVisit::query()->create([
                    'work_order_id' => $workOrder->id,
                    'visit_no' => ($latest?->visit_no ?? 0) + 1,
                    'starts_at' => $times['starts_at']
                        ->format('Y-m-d H:i:s'),
                    'ends_at' => $times['ends_at']
                        ->format('Y-m-d H:i:s'),
                    'zone_name' => $data['zone_name'],
                    'status' => 'draft',
                    'previous_visit_id' => null,
                    'reason' => $data['reason'],
                    'created_by' => $current->id,
                    'created_at' => now(),
                    'lock_version' => 0,
                ]);

                return [
                    'subject_type' => 'work_visit',
                    'subject_id' => $visit->id,
                    'to_state' => 'draft',
                    'reason' => $data['reason'],
                    'facts' => [
                        'work_order_id' => $workOrder->id,
                        'visit_id' => $visit->id,
                        'visit_no' => $visit->visit_no,
                        'starts_at' => $visit->starts_at
                            ->format('Y-m-d H:i:s'),
                        'ends_at' => $visit->ends_at
                            ->format('Y-m-d H:i:s'),
                        'zone_name' => $visit->zone_name,
                        'lock_version' => $visit->lock_version,
                    ],
                ];
            },
        );

        return WorkVisit::query()->findOrFail($history->subject_id);
    }

    /** @param array<string, mixed> $input */
    public function confirm(
        User $actor,
        int $visitId,
        array $input,
    ): WorkVisit {
        $this->commands->authorize($actor, ['koordinator']);

        $data = Validator::make($input, [
            'command_key' => ['bail', 'required', 'uuid'],
            'expected_visit_version' => ['required', 'integer', 'min:0'],
            'technician_ids' => ['required', 'array', 'list', 'min:1'],
            'technician_ids.*' => [
                'bail',
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
        ])->validate();

        $key = strtolower($data['command_key']);
        unset($data['command_key']);

        $data['expected_visit_version'] = (int) $data['expected_visit_version'];
        $data['technician_ids'] = array_map('intval', $data['technician_ids']);
        sort($data['technician_ids']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // Discovery is a hint. Authoritative checks happen under locks.
            $discovered = WorkVisit::query()->findOrFail($visitId);

            $participantIds = VisitBooking::query()
                ->where('visit_id', $visitId)
                ->pluck('technician_id')
                ->all();

            $resourceIds = array_values(array_unique(array_map(
                'intval',
                [...$data['technician_ids'], ...$participantIds],
            )));
            sort($resourceIds);

            try {
                $history = $this->commands->run(
                    $actor,
                    $key,
                    'visit.confirm',
                    ['visit_id' => $visitId, ...$data],
                    ['koordinator'],
                    function () use ($discovered): array {
                        $workOrder = WorkOrder::query()
                            ->lockForUpdate()
                            ->findOrFail($discovered->work_order_id);

                        $scopes = $workOrder->scopeItems()
                            ->where('disposition', 'active')
                            ->where('is_required', true)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();

                        return compact('workOrder', 'scopes');
                    },
                    function (array $context, User $current) use (
                        $visitId,
                        $data,
                        $resourceIds,
                    ): array {
                        $workOrder = $context['workOrder'];

                        if (! in_array(
                            $workOrder->origin_kind,
                            ['ticket', 'manual'],
                            true,
                        ) || blank($workOrder->acceptance_context_ref)
                            || $context['scopes']->isEmpty()) {
                            $this->commands->conflict(
                                'work_order_id',
                                'WORK_ORDER_M1_BASELINE_REQUIRED',
                            );
                        }

                        if (! in_array(
                            $workOrder->status,
                            ['assigned', 'in_progress', 'rework'],
                            true,
                        )) {
                            $this->commands->conflict(
                                'work_order_id',
                                'WORK_ORDER_STATE_CONFLICT',
                            );
                        }

                        // The command service has already locked the Coordinator.
                        // Acquire every technician mutex before visit/booking locks.
                        $users = [];

                        foreach ($resourceIds as $resourceId) {
                            $users[$resourceId] = User::query()
                                ->lockForUpdate()
                                ->find($resourceId);
                        }

                        $memberIds = DB::table('work_order_technicians')
                            ->where('work_order_id', $workOrder->id)
                            ->whereIn('user_id', $data['technician_ids'])
                            ->orderBy('user_id')
                            ->lockForUpdate()
                            ->pluck('user_id')
                            ->map(fn ($id): int => (int) $id)
                            ->all();

                        foreach ($data['technician_ids'] as $technicianId) {
                            $technician = $users[$technicianId] ?? null;

                            if (! $technician
                                || ! $technician->hasRole('teknisi')
                                || ! $technician->isActive()
                                || ! $technician->isApproved()
                                || ! in_array($technicianId, $memberIds, true)) {
                                throw ValidationException::withMessages([
                                    'technician_ids' => 'Pilih anggota WO yang berstatus teknisi aktif dan approved.',
                                ]);
                            }
                        }

                        $visit = WorkVisit::query()
                            ->lockForUpdate()
                            ->findOrFail($visitId);

                        if ((int) $visit->work_order_id !== (int) $workOrder->id) {
                            throw new VisitResourceSetChanged;
                        }

                        $bookings = VisitBooking::query()
                            ->where('visit_id', $visitId)
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

                        if ($visit->lock_version !== $data['expected_visit_version']) {
                            $this->commands->conflict(
                                'expected_visit_version',
                                'VISIT_VERSION_CONFLICT',
                            );
                        }

                        if ($visit->status !== 'draft' || $bookings->isNotEmpty()) {
                            $this->commands->conflict(
                                'visit_id',
                                'VISIT_STATE_CONFLICT',
                            );
                        }

                        if (! $visit->starts_at->lessThan($visit->ends_at)
                            || ! in_array(
                                $visit->zone_name,
                                DateTimeZone::listIdentifiers(
                                    DateTimeZone::ALL_WITH_BC,
                                ),
                                true,
                            )) {
                            $this->commands->conflict(
                                'visit_id',
                                'VISIT_INTERVAL_INVALID',
                            );
                        }

                        // Check the entire group before creating any booking.
                        foreach ($data['technician_ids'] as $technicianId) {
                            $this->assertTechnicianAvailable($technicianId, $visit);
                        }

                        $bookingIds = [];
                        $confirmedAt = now('UTC');

                        foreach ($data['technician_ids'] as $technicianId) {
                            $booking = VisitBooking::query()->create([
                                'visit_id' => $visit->id,
                                'technician_id' => $technicianId,
                                'status' => 'confirmed',
                                'confirmed_by' => $current->id,
                                'confirmed_at' => $confirmedAt,
                                'actual_start_at' => null,
                                'actual_end_at' => null,
                                'ended_reason' => null,
                            ]);

                            $bookingIds[] = $booking->id;
                        }

                        $visit->update([
                            'status' => 'confirmed',
                            'lock_version' => $visit->lock_version + 1,
                        ]);

                        return [
                            'subject_type' => 'work_visit',
                            'subject_id' => $visit->id,
                            'from_state' => 'draft',
                            'to_state' => 'confirmed',
                            'facts' => [
                                'work_order_id' => $workOrder->id,
                                'visit_id' => $visit->id,
                                'technician_ids' => $data['technician_ids'],
                                'booking_ids' => $bookingIds,
                                'expected_visit_version' => $data['expected_visit_version'],
                                'lock_version' => $visit->lock_version,
                                'starts_at' => $visit->starts_at
                                    ->format('Y-m-d H:i:s'),
                                'ends_at' => $visit->ends_at
                                    ->format('Y-m-d H:i:s'),
                                'zone_name' => $visit->zone_name,
                            ],
                        ];
                    },
                );

                return WorkVisit::query()->findOrFail($history->subject_id);
            } catch (VisitResourceSetChanged) {
                // The transaction has rolled back. Rediscover the complete set.
                continue;
            }
        }

        $this->commands->conflict(
            'visit_id',
            'VISIT_RESOURCES_CHANGED',
        );
    }

    /** @param array<string, mixed> $input */
    public function reschedule(User $actor, int $visitId, array $input): WorkVisit
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = Validator::make($input, $this->scheduleRules(true) + [
            'expected_visit_version' => ['required', 'integer', 'min:0'],
            'technician_ids' => ['required', 'array', 'list', 'min:1'],
            'technician_ids.*' => ['bail', 'required', 'integer', 'min:1', 'distinct'],
        ])->validate();
        $key = strtolower($data['command_key']);
        unset($data['command_key']);
        $data['expected_visit_version'] = (int) $data['expected_visit_version'];
        $data['technician_ids'] = array_map('intval', $data['technician_ids']);
        sort($data['technician_ids']);
        $times = $this->scheduleTimes($data);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $discovered = WorkVisit::query()->findOrFail($visitId);
            $participantIds = VisitBooking::query()->where('visit_id', $visitId)->pluck('technician_id')->all();
            $resourceIds = array_values(array_unique(array_map('intval', [...$participantIds, ...$data['technician_ids']])));
            sort($resourceIds);

            try {
                $history = $this->commands->run(
                    $actor, $key, 'visit.reschedule', ['visit_id' => $visitId, ...$data], ['koordinator'],
                    function () use ($discovered): array {
                        $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($discovered->work_order_id);
                        $scopes = $workOrder->scopeItems()->where('disposition', 'active')->where('is_required', true)
                            ->orderBy('id')->lockForUpdate()->get();

                        return compact('workOrder', 'scopes');
                    },
                    function (array $context, User $current) use ($visitId, $resourceIds, $data, $times): array {
                        $workOrder = $context['workOrder'];
                        if (! in_array($workOrder->origin_kind, ['ticket', 'manual'], true)
                            || blank($workOrder->acceptance_context_ref) || $context['scopes']->isEmpty()) {
                            $this->commands->conflict('work_order_id', 'WORK_ORDER_M1_BASELINE_REQUIRED');
                        }
                        if (! in_array($workOrder->status, ['assigned', 'in_progress', 'rework'], true)) {
                            $this->commands->conflict('work_order_id', 'WORK_ORDER_STATE_CONFLICT');
                        }

                        $resources = [];
                        foreach ($resourceIds as $resourceId) {
                            $resources[$resourceId] = User::query()->lockForUpdate()->find($resourceId);
                        }
                        $memberIds = DB::table('work_order_technicians')->where('work_order_id', $workOrder->id)
                            ->whereIn('user_id', $data['technician_ids'])->orderBy('user_id')->lockForUpdate()
                            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
                        $source = WorkVisit::query()->lockForUpdate()->findOrFail($visitId);
                        if ((int) $source->work_order_id !== (int) $workOrder->id) {
                            throw new VisitResourceSetChanged;
                        }
                        $bookings = $source->bookings()->orderBy('id')->lockForUpdate()->get();
                        $currentIds = $bookings->pluck('technician_id')->map(fn ($id): int => (int) $id)->all();
                        if (array_diff($currentIds, $resourceIds)) {
                            throw new VisitResourceSetChanged;
                        }
                        if ($source->lock_version !== $data['expected_visit_version']) {
                            $this->commands->conflict('expected_visit_version', 'VISIT_VERSION_CONFLICT');
                        }
                        if ($source->status !== 'confirmed'
                            || $bookings->contains(fn (VisitBooking $booking): bool => $booking->actual_start_at !== null || $booking->actual_end_at !== null)) {
                            $this->commands->conflict('visit_id', 'VISIT_RESCHEDULE_NOT_ALLOWED');
                        }
                        if ($bookings->where('status', 'confirmed')->isEmpty()
                            || $bookings->contains(fn (VisitBooking $booking): bool => ! in_array($booking->status, ['confirmed', 'cancelled'], true))
                            || $source->successorVisit()->lockForUpdate()->first() !== null) {
                            $this->commands->conflict('visit_id', 'VISIT_BOOKING_STATE_ANOMALY');
                        }
                        foreach ($data['technician_ids'] as $technicianId) {
                            $technician = $resources[$technicianId];
                            if (! $technician || ! $technician->hasRole('teknisi') || ! $technician->isActive()
                                || ! $technician->isApproved() || ! in_array($technicianId, $memberIds, true)) {
                                throw ValidationException::withMessages(['technician_ids' => 'Pilih anggota WO yang berstatus teknisi aktif dan approved.']);
                            }
                        }
                        $oldIds = $bookings->where('status', 'confirmed')->pluck('technician_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                        if ($source->starts_at->equalTo($times['starts_at']) && $source->ends_at->equalTo($times['ends_at'])
                            && $oldIds === $data['technician_ids']) {
                            $this->commands->conflict('visit_id', 'VISIT_RESCHEDULE_NO_CHANGES');
                        }

                        $replacement = new WorkVisit([
                            'work_order_id' => $workOrder->id,
                            'starts_at' => $times['starts_at']->format('Y-m-d H:i:s'),
                            'ends_at' => $times['ends_at']->format('Y-m-d H:i:s'),
                            'zone_name' => $data['zone_name'],
                        ]);
                        foreach ($data['technician_ids'] as $technicianId) {
                            $this->assertTechnicianAvailable($technicianId, $replacement, $source->id);
                        }
                        $latest = $workOrder->visits()->orderByDesc('visit_no')->lockForUpdate()->firstOrFail();
                        $replacement->fill([
                            'visit_no' => $latest->visit_no + 1, 'previous_visit_id' => $source->id,
                            'status' => 'confirmed', 'reason' => $data['reason'], 'created_by' => $current->id,
                            'created_at' => now(), 'lock_version' => 1,
                        ])->save();
                        $cancelledIds = [];
                        foreach ($bookings->where('status', 'confirmed') as $booking) {
                            $booking->update(['status' => 'cancelled', 'ended_reason' => $data['reason']]);
                            $cancelledIds[] = $booking->id;
                        }
                        $source->update(['status' => 'superseded', 'lock_version' => $source->lock_version + 1]);
                        $bookingIds = [];
                        foreach ($data['technician_ids'] as $technicianId) {
                            $bookingIds[] = $replacement->bookings()->create([
                                'technician_id' => $technicianId, 'status' => 'confirmed',
                                'confirmed_by' => $current->id, 'confirmed_at' => now('UTC'),
                                'actual_start_at' => null, 'actual_end_at' => null, 'ended_reason' => null,
                            ])->id;
                        }

                        return [
                            'subject_type' => 'work_visit', 'subject_id' => $replacement->id,
                            'from_state' => 'confirmed', 'to_state' => 'confirmed', 'reason' => $data['reason'],
                            'facts' => [
                                'work_order_id' => $workOrder->id, 'source_visit_id' => $source->id,
                                'visit_id' => $replacement->id, 'source_status_after' => 'superseded',
                                'expected_visit_version' => $data['expected_visit_version'],
                                'source_lock_version' => $source->lock_version, 'lock_version' => $replacement->lock_version,
                                'old_technician_ids' => $oldIds, 'technician_ids' => $data['technician_ids'],
                                'cancelled_booking_ids' => $cancelledIds, 'booking_ids' => $bookingIds,
                                'old_starts_at' => $source->getRawOriginal('starts_at'), 'old_ends_at' => $source->getRawOriginal('ends_at'),
                                'old_zone_name' => $source->zone_name,
                                'starts_at' => $replacement->getRawOriginal('starts_at'), 'ends_at' => $replacement->getRawOriginal('ends_at'),
                                'zone_name' => $replacement->zone_name,
                            ],
                        ];
                    },
                );

                return WorkVisit::query()->findOrFail($history->subject_id);
            } catch (VisitResourceSetChanged) {
                continue;
            }
        }

        $this->commands->conflict('visit_id', 'VISIT_RESOURCES_CHANGED');
    }

    /** @return array<string, array<mixed>> */
    private function scheduleRules(bool $reasonRequired = false): array
    {
        return [
            'command_key' => ['bail', 'required', 'uuid'],
            'starts_at' => [
                'bail',
                'required',
                'date_format:Y-m-d\TH:iP',
            ],
            'ends_at' => [
                'bail',
                'required',
                'date_format:Y-m-d\TH:iP',
            ],
            'zone_name' => [
                'bail',
                'required',
                'string',
                'max:64',
                Rule::in(
                    DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC)
                ),
            ],
            'reason' => [
                'bail',
                ($reasonRequired ? 'required' : 'nullable'),
                'string',
                'max:10000',
                'encoding:UTF-8',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}
     */
    private function scheduleTimes(array $data): array
    {
        $times = [];

        foreach (['starts_at', 'ends_at'] as $field) {
            $time = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:iP',
                $data[$field],
            );

            if ($time->setTimezone($data['zone_name'])
                ->format('Y-m-d\TH:iP') !== $data[$field]) {
                throw ValidationException::withMessages([
                    $field => 'Waktu dan UTC offset harus sesuai timezone.',
                ]);
            }

            $times[$field] = $time->utc();
        }

        if (! $times['ends_at']->greaterThan($times['starts_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => 'Waktu selesai harus setelah waktu mulai.',
            ]);
        }

        return $times;
    }

    private function assertTechnicianAvailable(
        int $technicianId,
        WorkVisit $visit,
        ?int $excludedVisitId = null,
    ): void {
        // Direct join + locking read observes committed reservations after
        // waiting for the shared technician mutex, including across Work Orders.
        $slots = DB::table('visit_bookings as bookings')
            ->join('work_visits as visits', 'visits.id', '=', 'bookings.visit_id')
            ->where('bookings.technician_id', $technicianId)
            ->when($excludedVisitId !== null, fn ($query) => $query->where('visits.id', '!=', $excludedVisitId))
            ->whereIn('bookings.status', ['confirmed', 'in_progress'])
            ->orderBy('bookings.id')
            ->lockForUpdate()
            ->get([
                'bookings.id',
                'bookings.status as booking_status',
                'bookings.actual_start_at',
                'bookings.actual_end_at',
                'visits.status as visit_status',
                'visits.starts_at',
                'visits.ends_at',
            ]);

        foreach ($slots as $slot) {
            $invalidState = ! in_array(
                $slot->visit_status,
                ['confirmed', 'in_progress'],
                true,
            ) || $slot->actual_end_at !== null
                || ($slot->booking_status === 'confirmed'
                    && $slot->actual_start_at !== null)
                || ($slot->booking_status === 'in_progress'
                    && ($slot->actual_start_at === null
                        || $slot->visit_status !== 'in_progress'));

            if ($invalidState) {
                $this->commands->conflict(
                    'technician_ids',
                    'VISIT_BOOKING_STATE_ANOMALY',
                );
            }

            $startsAt = CarbonImmutable::parse($slot->starts_at, 'UTC');
            $endsAt = CarbonImmutable::parse($slot->ends_at, 'UTC');

            if ($startsAt->lessThan($visit->ends_at)
                && $visit->starts_at->lessThan($endsAt)) {
                $this->commands->conflict(
                    'technician_ids',
                    'BOOKING_TIME_CONFLICT',
                );
            }
        }
    }
}
