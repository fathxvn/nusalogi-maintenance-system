<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\WorkVisit;
use App\Services\CancelVisitBookingService;
use App\Services\CreateWorkOrderService;
use App\Services\VisitSchedulingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VisitReschedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacement_preserves_historical_intervals_cancelled_bookings_membership_and_wo_state(): void
    {
        [$actor, $source, $members] = $this->context();
        $old = $source->bookings()->where('technician_id', $members[1]->id)->sole();
        app(CancelVisitBookingService::class)->cancel($actor, $old->id, [
            'command_key' => (string) Str::uuid(), 'expected_visit_version' => 1,
            'expected_booking_status' => 'confirmed', 'reason' => 'Original cancellation',
        ]);
        $source->refresh();
        $before = $source->getRawOriginal();
        $membership = DB::table('work_order_technicians')->get()->map(fn ($row): array => (array) $row)->all();
        $payload = $this->payload($source, [$members[0]->id, $members[2]->id]);

        $replacement = app(VisitSchedulingService::class)->reschedule($actor, $source->id, $payload);

        $this->assertSame('superseded', $source->fresh()->status);
        $this->assertSame(3, $source->fresh()->lock_version);
        $this->assertSame($before['starts_at'], $source->fresh()->getRawOriginal('starts_at'));
        $this->assertSame($before['ends_at'], $source->fresh()->getRawOriginal('ends_at'));
        $this->assertSame('Original cancellation', $old->fresh()->ended_reason);
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('confirmed', $replacement->status);
        $this->assertSame(1, $replacement->lock_version);
        $this->assertSame(2, $replacement->visit_no);
        $this->assertSame($source->id, $replacement->previous_visit_id);
        $this->assertSame('2026-11-12 03:00:00.000000', $replacement->getRawOriginal('starts_at'));
        $this->assertSame('2026-11-12 05:00:00.000000', $replacement->getRawOriginal('ends_at'));
        $this->assertSame($payload['technician_ids'], $replacement->bookings()->orderBy('technician_id')->pluck('technician_id')->all());
        $this->assertSame('assigned', $source->workOrder->fresh()->status);
        $this->assertSame($membership, DB::table('work_order_technicians')->get()->map(fn ($row): array => (array) $row)->all());
        foreach ($source->fresh()->bookings as $booking) {
            $this->assertSame('cancelled', $booking->status);
            $this->assertNull($booking->actual_start_at);
            $this->assertNull($booking->actual_end_at);
        }
        foreach ($replacement->bookings as $booking) {
            $this->assertSame('confirmed', $booking->status);
            $this->assertSame($actor->id, $booking->confirmed_by);
            $this->assertNotNull($booking->confirmed_at);
            $this->assertNull($booking->actual_start_at);
            $this->assertNull($booking->actual_end_at);
        }
        $history = OperationHistory::query()->where('action', 'visit.reschedule')->sole();
        $this->assertSame($actor->id, $history->actor_id);
        $this->assertSame($replacement->id, $history->subject_id);
        $this->assertSame($source->id, $history->facts['source_visit_id']);
        $this->assertSame($payload['reason'], $history->reason);
        $this->assertSame('superseded', $history->facts['source_status_after']);
    }

    public function test_participant_only_change_and_cross_date_schedule_are_supported(): void
    {
        [$actor, $source, $members] = $this->context();
        $service = app(VisitSchedulingService::class);
        $replacement = $service->reschedule($actor, $source->id, [
            ...$this->payload($source, [$members[2]->id]),
            'starts_at' => '2026-11-12T09:00+07:00', 'ends_at' => '2026-11-12T11:00+07:00',
        ]);
        $this->assertSame([$members[2]->id], $replacement->bookings()->pluck('technician_id')->all());
        $next = $service->reschedule($actor, $replacement->id, [
            ...$this->payload($replacement, [$members[2]->id]),
            'starts_at' => '2026-11-12T23:30+08:00', 'ends_at' => '2026-11-13T01:00+08:00', 'zone_name' => 'Asia/Makassar',
        ]);
        $this->assertSame('2026-11-12 15:30:00.000000', $next->getRawOriginal('starts_at'));
        $this->assertSame('2026-11-12 17:00:00.000000', $next->getRawOriginal('ends_at'));
        $this->assertSame('Asia/Makassar', $next->zone_name);
        $this->assertSame(3, $next->visit_no);
    }

    #[DataProvider('conflictParents')]
    public function test_only_the_source_slot_is_excluded_from_overlap_checks(bool $sameWorkOrder): void
    {
        [$actor, $source, $members] = $this->context();
        $service = app(VisitSchedulingService::class);
        if ($sameWorkOrder) {
            $other = $service->createDraft($actor, $source->work_order_id, [
                'command_key' => (string) Str::uuid(), 'starts_at' => '2026-11-12T12:00+07:00',
                'ends_at' => '2026-11-12T14:00+07:00', 'zone_name' => 'Asia/Jakarta',
            ]);
            $service->confirm($actor, $other->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$members[0]->id]]);
        } else {
            $this->context($members, '2026-11-12T12:00+07:00', '2026-11-12T14:00+07:00');
        }
        $this->reject($actor, $source, [...$this->payload($source, [$members[0]->id]), 'ends_at' => '2026-11-12T13:00+07:00'], 'BOOKING_TIME_CONFLICT');
    }

    public static function conflictParents(): array
    {
        return ['same WO' => [true], 'different WO' => [false]];
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_requests_leave_the_source_and_history_unchanged(array $changes, string $message, int $status): void
    {
        [$actor, $source, $members] = $this->context();
        $this->reject($actor, $source, [...$this->payload($source, [$members[0]->id, $members[1]->id]), ...$changes], $message, $status);
    }

    public static function invalidChanges(): array
    {
        return [
            'stale' => [['expected_visit_version' => 0], 'VISIT_VERSION_CONFLICT', 409],
            'no-op' => [['starts_at' => '2026-11-12T09:00+07:00', 'ends_at' => '2026-11-12T11:00+07:00'], 'VISIT_RESCHEDULE_NO_CHANGES', 409],
            'zone-only no-op' => [['starts_at' => '2026-11-12T10:00+08:00', 'ends_at' => '2026-11-12T12:00+08:00', 'zone_name' => 'Asia/Makassar'], 'VISIT_RESCHEDULE_NO_CHANGES', 409],
            'empty participants' => [['technician_ids' => []], 'technician_ids', 422],
            'duplicate participants' => [['technician_ids' => [1, 1]], 'technician_ids.0', 422],
            'blank reason' => [['reason' => '   '], 'reason', 422],
            'invalid zone' => [['zone_name' => 'invalid'], 'zone_name', 422],
            'mismatched offset' => [['zone_name' => 'Asia/Jayapura'], 'starts_at', 422],
            'inverted interval' => [['ends_at' => '2026-11-12T09:00+07:00'], 'ends_at', 422],
        ];
    }

    #[DataProvider('ineligibleMembers')]
    public function test_new_participants_must_be_current_eligible_members(array $attributes, bool $detach): void
    {
        [$actor, $source, $members] = $this->context();
        $members[2]->forceFill($attributes)->save();
        if ($detach) {
            $source->workOrder->technicians()->detach($members[2]);
        }
        $this->reject($actor, $source, $this->payload($source, [$members[2]->id]), 'technician_ids', 422);
    }

    public static function ineligibleMembers(): array
    {
        return [
            'inactive' => [['is_active' => false], false], 'pending' => [['approval_status' => 'pending'], false],
            'rejected' => [['approval_status' => 'rejected'], false], 'role changed' => [['role' => 'admin'], false],
            'not a member' => [[], true],
        ];
    }

    #[DataProvider('startedEvidence')]
    public function test_any_actual_evidence_or_nonconfirmed_source_blocks_replacement(array $visit, array $booking): void
    {
        [$actor, $source, $members] = $this->context();
        $source->forceFill($visit)->save();
        $source->bookings()->firstOrFail()->forceFill($booking)->save();
        $this->reject($actor, $source, $this->payload($source, [$members[0]->id]), 'VISIT_RESCHEDULE_NOT_ALLOWED');
    }

    public static function startedEvidence(): array
    {
        return [
            'draft' => [['status' => 'draft'], []], 'cancelled' => [['status' => 'cancelled'], []],
            'in progress' => [['status' => 'in_progress'], ['actual_start_at' => '2026-11-12 02:00:00']],
            'start on confirmed' => [[], ['actual_start_at' => '2026-11-12 02:00:00']],
            'historical cancelled actuals' => [[], ['status' => 'cancelled', 'actual_start_at' => '2026-11-12 02:00:00', 'actual_end_at' => '2026-11-12 03:00:00']],
        ];
    }

    public function test_replay_returns_the_same_successor_and_changed_payload_is_rejected(): void
    {
        [$actor, $source, $members] = $this->context();
        $payload = $this->payload($source, [$members[1]->id, $members[0]->id]);
        $service = app(VisitSchedulingService::class);
        $first = $service->reschedule($actor, $source->id, $payload);
        $replay = $service->reschedule($actor, $source->id, [...$payload, 'technician_ids' => array_map('strval', array_reverse($payload['technician_ids']))]);
        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, $source->successorVisit()->count());
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.reschedule')->count());
        $this->reject($actor, $source, [...$payload, 'reason' => 'Changed reason'], 'COMMAND_KEY_REUSED');
    }

    #[DataProvider('revokedActors')]
    public function test_current_actor_authorization_is_required_even_for_replay(array $attributes): void
    {
        [$actor, $source, $members] = $this->context();
        $payload = $this->payload($source, [$members[0]->id]);
        app(VisitSchedulingService::class)->reschedule($actor, $source->id, $payload);
        User::query()->whereKey($actor->id)->update($attributes);
        $this->expectException(AuthorizationException::class);
        app(VisitSchedulingService::class)->reschedule($actor, $source->id, $payload);
    }

    public static function revokedActors(): array
    {
        return ['admin' => [['role' => 'admin']], 'technician' => [['role' => 'teknisi']], 'inactive' => [['is_active' => false]], 'pending' => [['approval_status' => 'pending']]];
    }

    #[DataProvider('failurePoints')]
    public function test_failure_rolls_back_all_effects_and_same_key_can_retry(string $prefix): void
    {
        [$actor, $source, $members] = $this->context();
        $payload = $this->payload($source, [$members[0]->id]);
        $before = $this->snapshot();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($prefix, &$failed): void {
            if (! $failed && str_starts_with($sql, $prefix)) {
                $failed = true;
                throw new RuntimeException('Reschedule injected failure');
            }
        });
        try {
            app(VisitSchedulingService::class)->reschedule($actor, $source->id, $payload);
            $this->fail('The injected failure must abort replacement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Reschedule injected failure', $exception->getMessage());
        }
        $this->assertTrue($failed);
        $this->assertSame($before, $this->snapshot());
        $replacement = app(VisitSchedulingService::class)->reschedule($actor, $source->id, $payload);
        $this->assertSame('confirmed', $replacement->status);
        $this->assertSame(1, $source->successorVisit()->count());
    }

    public static function failurePoints(): array
    {
        return ['replacement' => ['insert into `work_visits`'], 'cancel old' => ['update `visit_bookings`'], 'supersede source' => ['update `work_visits`'], 'new booking' => ['insert into `visit_bookings`'], 'audit' => ['insert into `operation_history`']];
    }

    public function test_one_conflicting_replacement_participant_keeps_the_entire_old_group_reserved(): void
    {
        [$actor, $source, $members] = $this->context();
        $others = User::factory()->count(2)->create(['role' => 'teknisi'])->all();
        $this->context([$members[2], ...$others], '2026-11-12T10:00+07:00', '2026-11-12T12:00+07:00');
        $this->reject($actor, $source, $this->payload($source, [$members[0]->id, $members[2]->id]), 'BOOKING_TIME_CONFLICT');
        $this->assertSame(2, $source->bookings()->where('status', 'confirmed')->count());
    }

    public function test_next_visit_number_uses_the_latest_visit_and_removed_ineligible_participants_can_be_replaced(): void
    {
        [$actor, $source, $members] = $this->context();
        $members[1]->forceFill(['is_active' => false])->save();
        $service = app(VisitSchedulingService::class);
        $service->createDraft($actor, $source->work_order_id, [
            'command_key' => (string) Str::uuid(), 'starts_at' => '2026-11-14T09:00+07:00',
            'ends_at' => '2026-11-14T11:00+07:00', 'zone_name' => 'Asia/Jakarta',
        ]);
        $replacement = $service->reschedule($actor, $source->id, $this->payload($source, [$members[0]->id]));
        $this->assertSame(3, $replacement->visit_no);
        $this->assertSame([$members[0]->id], $replacement->bookings()->pluck('technician_id')->all());
        $this->assertSame('cancelled', $source->bookings()->where('technician_id', $members[1]->id)->sole()->status);
    }

    private function reject(User $actor, WorkVisit $source, array $payload, string $message, int $status = 409): void
    {
        $before = $this->snapshot();
        try {
            app(VisitSchedulingService::class)->reschedule($actor, $source->id, $payload);
            $this->fail('Invalid replacement must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame($status, $exception->status);
            if ($status === 422) {
                $this->assertArrayHasKey($message, $exception->errors());
            } else {
                $this->assertContains($message, array_merge(...array_values($exception->errors())));
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        return array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), ['work_visits', 'visit_bookings', 'operation_history', 'work_orders', 'work_order_technicians']);
    }

    /** @return array{User, WorkVisit, list<User>} */
    private function context(?array $members = null, string $startsAt = '2026-11-12T09:00+07:00', string $endsAt = '2026-11-12T11:00+07:00'): array
    {
        $actor = User::factory()->create(['role' => 'koordinator']);
        $members ??= User::factory()->count(3)->create(['role' => 'teknisi'])->all();
        $site = Customer::query()->create(['name' => 'Reschedule customer'])->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
        $wo = app(CreateWorkOrderService::class)->create($actor, [
            'command_key' => (string) Str::uuid(), 'origin_kind' => 'manual', 'manual_reason' => 'Test request',
            'initial_scope' => ['description' => 'Restore camera', 'acceptance_criteria' => 'Live feed'],
            'customer_site_id' => $site->id, 'title' => 'Camera', 'description' => 'Offline', 'service_type' => 'maintenance', 'category' => 'cctv', 'priority' => 'medium', 'asset_ids' => [],
        ]);
        $wo->technicians()->attach(array_map(fn (User $user): int => $user->id, $members), ['assigned_at' => now()]);
        $wo->forceFill(['status' => 'assigned'])->save();
        $service = app(VisitSchedulingService::class);
        $source = $service->createDraft($actor, $wo->id, ['command_key' => (string) Str::uuid(), 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'zone_name' => 'Asia/Jakarta']);
        $source = $service->confirm($actor, $source->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$members[0]->id, $members[1]->id]]);

        return [$actor, $source, $members];
    }

    private function payload(WorkVisit $source, array $ids): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_visit_version' => $source->lock_version, 'starts_at' => '2026-11-12T10:00+07:00', 'ends_at' => '2026-11-12T12:00+07:00', 'zone_name' => 'Asia/Jakarta', 'technician_ids' => $ids, 'reason' => 'Site access changed'];
    }
}
