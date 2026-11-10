<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CancelVisitBookingService;
use App\Services\CreateWorkOrderService;
use App\Services\VisitSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class CancelVisitBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_one_participant_preserves_remaining_booking(): void
    {
        [$coordinator, $workOrder, $visit] = $this->context(2);

        $bookings = $visit->bookings()->orderBy('id')->get();
        $target = $bookings[0];
        $remaining = $bookings[1];

        $memberIds = $workOrder->technicians()
            ->orderBy('users.id')
            ->pluck('users.id')
            ->all();

        $payload = $this->payload($visit, $target);

        $cancelled = app(CancelVisitBookingService::class)->cancel(
            $coordinator,
            $target->id,
            $payload,
        );

        $this->assertSame($target->id, $cancelled->id);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($payload['reason'], $cancelled->ended_reason);
        $this->assertNull($cancelled->actual_start_at);
        $this->assertNull($cancelled->actual_end_at);

        $this->assertSame('confirmed', $remaining->fresh()->status);
        $this->assertSame('confirmed', $visit->fresh()->status);
        $this->assertSame(2, $visit->fresh()->lock_version);
        $this->assertSame('assigned', $workOrder->fresh()->status);
        $this->assertSame(
            $memberIds,
            $workOrder->technicians()
                ->orderBy('users.id')
                ->pluck('users.id')
                ->all(),
        );
        $this->assertDatabaseCount('visit_bookings', 2);

        $history = OperationHistory::query()
            ->where('action', 'visit.booking.cancel')
            ->sole();

        $this->assertSame('visit_booking', $history->subject_type);
        $this->assertSame($target->id, $history->subject_id);
        $this->assertSame($coordinator->id, $history->actor_id);
        $this->assertSame('confirmed', $history->from_state);
        $this->assertSame('cancelled', $history->to_state);
        $this->assertSame($payload['reason'], $history->reason);
    }

    public function test_cancelling_last_unstarted_participant_cancels_visit(): void
    {
        [$coordinator, $workOrder, $visit] = $this->context();
        $booking = $visit->bookings()->sole();

        $startsAt = $visit->starts_at->format('Y-m-d H:i:s');
        $endsAt = $visit->ends_at->format('Y-m-d H:i:s');

        $cancelled = app(CancelVisitBookingService::class)->cancel(
            $coordinator,
            $booking->id,
            $this->payload($visit, $booking),
        );

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->actual_start_at);
        $this->assertNull($cancelled->actual_end_at);

        $visit->refresh();

        $this->assertSame('cancelled', $visit->status);
        $this->assertSame(2, $visit->lock_version);
        $this->assertSame(
            $startsAt,
            $visit->starts_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            $endsAt,
            $visit->ends_at->format('Y-m-d H:i:s'),
        );

        $this->assertSame('assigned', $workOrder->fresh()->status);
        $this->assertSame(1, $workOrder->technicians()->count());
        $this->assertDatabaseCount('visit_bookings', 1);
    }

    public function test_cancelling_an_in_progress_booking_is_rejected(): void
    {
        [$coordinator, $workOrder, $visit] = $this->context();
        $booking = $visit->bookings()->sole();

        $workOrder->forceFill(['status' => 'in_progress'])->save();

        $booking->update([
            'status' => 'in_progress',
            'actual_start_at' => '2026-11-12 02:00:00',
        ]);

        $visit->update([
            'status' => 'in_progress',
            'lock_version' => 2,
        ]);

        try {
            app(CancelVisitBookingService::class)->cancel(
                $coordinator,
                $booking->id,
                $this->payload($visit, $booking),
            );

            $this->fail('Interruption must remain disabled.');
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame(
                ['BOOKING_CANCELLATION_NOT_ALLOWED'],
                $exception->errors()['booking_id'],
            );
        }

        $booking->refresh();

        $this->assertSame('in_progress', $booking->status);
        $this->assertSame(
            '2026-11-12 02:00:00',
            $booking->actual_start_at->format('Y-m-d H:i:s'),
        );
        $this->assertNull($booking->actual_end_at);
        $this->assertNull($booking->ended_reason);
        $this->assertSame('in_progress', $visit->fresh()->status);
        $this->assertSame(2, $visit->fresh()->lock_version);
        $this->assertSame(
            0,
            OperationHistory::query()
                ->where('action', 'visit.booking.cancel')
                ->count(),
        );
    }

    public function test_cancelling_remaining_confirmed_participant_completes_started_visit(): void
    {
        [$coordinator, $workOrder, $visit] = $this->context(2);

        $bookings = $visit->bookings()->orderBy('id')->get();
        $completed = $bookings[0];
        $target = $bookings[1];

        $workOrder->forceFill(['status' => 'in_progress'])->save();

        $completed->update([
            'status' => 'completed',
            'actual_start_at' => '2026-11-12 02:00:00',
            'actual_end_at' => '2026-11-12 03:00:00',
        ]);

        $visit->update([
            'status' => 'in_progress',
            'lock_version' => 3,
        ]);

        $cancelled = app(CancelVisitBookingService::class)->cancel(
            $coordinator,
            $target->id,
            $this->payload($visit, $target),
        );

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->actual_start_at);
        $this->assertNull($cancelled->actual_end_at);
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame(4, $visit->fresh()->lock_version);

        $completed->refresh();

        $this->assertSame('completed', $completed->status);
        $this->assertSame(
            '2026-11-12 02:00:00',
            $completed->actual_start_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-11-12 03:00:00',
            $completed->actual_end_at->format('Y-m-d H:i:s'),
        );

        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertSame(2, $workOrder->technicians()->count());
        $this->assertDatabaseCount('visit_bookings', 2);
    }

    /** @return array{User, WorkOrder, WorkVisit} */
    private function context(int $participantCount = 1): array
    {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $technicians = User::factory()->count($participantCount)->create([
            'role' => 'teknisi',
        ]);

        $customer = Customer::query()->create([
            'name' => 'PT Cancellation Testing',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Office',
            'address' => 'Bekasi',
        ]);

        $workOrder = app(CreateWorkOrderService::class)->create(
            $coordinator,
            [
                'command_key' => (string) Str::uuid(),
                'origin_kind' => 'manual',
                'manual_reason' => 'Cancellation test fixture',
                'initial_scope' => [
                    'description' => 'Restore camera feed',
                    'acceptance_criteria' => 'Live feed works',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Camera inspection',
                'description' => 'Camera offline',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ],
        );

        foreach ($technicians as $technician) {
            $workOrder->technicians()->attach($technician->id, [
                'assigned_at' => now(),
            ]);
        }

        $workOrder->forceFill(['status' => 'assigned'])->save();

        $visit = app(VisitSchedulingService::class)->createDraft(
            $coordinator,
            $workOrder->id,
            [
                'command_key' => (string) Str::uuid(),
                'starts_at' => '2026-11-12T09:00+07:00',
                'ends_at' => '2026-11-12T11:00+07:00',
                'zone_name' => 'Asia/Jakarta',
            ],
        );

        $visit = app(VisitSchedulingService::class)->confirm(
            $coordinator,
            $visit->id,
            [
                'command_key' => (string) Str::uuid(),
                'expected_visit_version' => 0,
                'technician_ids' => $technicians->modelKeys(),
            ],
        );

        return [$coordinator, $workOrder, $visit];
    }

    private function payload(
        WorkVisit $visit,
        VisitBooking $booking,
    ): array {
        return [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->lock_version,
            'expected_booking_status' => $booking->status,
            'reason' => 'Penyesuaian peserta kunjungan',
        ];
    }

    #[DataProvider('unauthorizedActors')]
public function test_cancel_rechecks_current_actor_permissions(
    array $attributes,
): void {
    [$coordinator, , $visit] = $this->context();
    $booking = $visit->bookings()->sole();

    DB::table('users')
        ->where('id', $coordinator->id)
        ->update($attributes);

    $rejected = false;

    try {
        app(CancelVisitBookingService::class)->cancel(
            $coordinator,
            $booking->id,
            $this->payload($visit, $booking),
        );
    } catch (AuthorizationException) {
        $rejected = true;
    }

    $this->assertTrue($rejected);
    $this->assertSame('confirmed', $booking->fresh()->status);
    $this->assertSame('confirmed', $visit->fresh()->status);
    $this->assertSame(1, $visit->fresh()->lock_version);
    $this->assertSame(
        0,
        OperationHistory::query()
            ->where('action', 'visit.booking.cancel')
            ->count(),
    );
}

public static function unauthorizedActors(): array
{
    return [
        'admin' => [['role' => 'admin']],
        'technician' => [['role' => 'teknisi']],
        'missing role' => [['role' => null]],
        'inactive' => [['is_active' => false]],
        'pending approval' => [['approval_status' => 'pending']],
        'rejected approval' => [['approval_status' => 'rejected']],
    ];
}

public function test_stale_visit_version_rejects_cancellation(): void
{
    [$coordinator, , $visit] = $this->context();
    $booking = $visit->bookings()->sole();

    try {
        app(CancelVisitBookingService::class)->cancel(
            $coordinator,
            $booking->id,
            [
                ...$this->payload($visit, $booking),
                'expected_visit_version' => 0,
            ],
        );

        $this->fail('Stale visit version must be rejected.');
    } catch (ValidationException $exception) {
        $this->assertSame(409, $exception->status);
        $this->assertSame(
            ['VISIT_VERSION_CONFLICT'],
            $exception->errors()['expected_visit_version'],
        );
    }

    $this->assertSame('confirmed', $booking->fresh()->status);
    $this->assertSame(1, $visit->fresh()->lock_version);
    $this->assertNull($booking->fresh()->ended_reason);
}

public function test_stale_booking_status_rejects_cancellation(): void
{
    [$coordinator, , $visit] = $this->context();
    $booking = $visit->bookings()->sole();

    try {
        app(CancelVisitBookingService::class)->cancel(
            $coordinator,
            $booking->id,
            [
                ...$this->payload($visit, $booking),
                'expected_booking_status' => 'in_progress',
            ],
        );

        $this->fail('Stale booking status must be rejected.');
    } catch (ValidationException $exception) {
        $this->assertSame(409, $exception->status);
        $this->assertSame(
            ['BOOKING_STATE_CONFLICT'],
            $exception->errors()['expected_booking_status'],
        );
    }

    $this->assertSame('confirmed', $booking->fresh()->status);
    $this->assertSame('confirmed', $visit->fresh()->status);
    $this->assertSame(1, $visit->fresh()->lock_version);
}

public function test_same_command_replays_without_duplicate_cancellation(): void
{
    [$coordinator, , $visit] = $this->context();
    $booking = $visit->bookings()->sole();
    $payload = $this->payload($visit, $booking);
    $service = app(CancelVisitBookingService::class);

    $first = $service->cancel(
        $coordinator,
        $booking->id,
        $payload,
    );

    $replay = $service->cancel(
        $coordinator,
        $booking->id,
        $payload,
    );

    $this->assertSame($first->id, $replay->id);
    $this->assertSame('cancelled', $replay->status);
    $this->assertSame('cancelled', $visit->fresh()->status);
    $this->assertSame(2, $visit->fresh()->lock_version);
    $this->assertDatabaseCount('visit_bookings', 1);
    $this->assertSame(
        1,
        OperationHistory::query()
            ->where('action', 'visit.booking.cancel')
            ->count(),
    );
}

public function test_same_key_with_changed_reason_is_rejected(): void
{
    [$coordinator, , $visit] = $this->context();
    $booking = $visit->bookings()->sole();
    $payload = $this->payload($visit, $booking);
    $service = app(CancelVisitBookingService::class);

    $service->cancel($coordinator, $booking->id, $payload);

    try {
        $service->cancel(
            $coordinator,
            $booking->id,
            [...$payload, 'reason' => 'Alasan berbeda'],
        );

        $this->fail('Changed reason must not reuse a committed key.');
    } catch (ValidationException $exception) {
        $this->assertSame(409, $exception->status);
        $this->assertSame(
            ['COMMAND_KEY_REUSED'],
            $exception->errors()['command_key'],
        );
    }

    $this->assertSame(
        $payload['reason'],
        $booking->fresh()->ended_reason,
    );
    $this->assertSame(2, $visit->fresh()->lock_version);
    $this->assertSame(
        1,
        OperationHistory::query()
            ->where('action', 'visit.booking.cancel')
            ->count(),
    );
}

public function test_coordinator_can_cancel_unstarted_booking_of_inactive_technician(): void
{
    [$coordinator, , $visit] = $this->context();
    $booking = $visit->bookings()->sole();

    DB::table('users')
        ->where('id', $booking->technician_id)
        ->update(['is_active' => false]);

    $cancelled = app(CancelVisitBookingService::class)->cancel(
        $coordinator,
        $booking->id,
        $this->payload($visit, $booking),
    );

    $this->assertSame('cancelled', $cancelled->status);
    $this->assertSame('cancelled', $visit->fresh()->status);
    $this->assertNull($cancelled->actual_start_at);
    $this->assertNull($cancelled->actual_end_at);
    $this->assertDatabaseCount('work_order_technicians', 1);

    $history = OperationHistory::query()
        ->where('action', 'visit.booking.cancel')
        ->sole();

    $this->assertFalse(
        $history->facts['technician_eligible_at_cancel'],
    );
}

#[DataProvider('cancellationFailurePoints')]
public function test_failed_cancellation_rolls_back_and_allows_same_key_retry(
    string $sqlPrefix,
): void {
    [$coordinator, $workOrder, $visit] = $this->context();
    $booking = $visit->bookings()->sole();
    $payload = $this->payload($visit, $booking);
    $historyCount = OperationHistory::query()->count();
    $failed = false;

    DB::connection()->beforeExecuting(
        function (string $sql) use ($sqlPrefix, &$failed): void {
            if (! $failed && str_starts_with($sql, $sqlPrefix)) {
                $failed = true;

                throw new RuntimeException(
                    'Simulated cancellation failure.',
                );
            }
        },
    );

    $service = app(CancelVisitBookingService::class);

    try {
        $service->cancel(
            $coordinator,
            $booking->id,
            $payload,
        );

        $this->fail('Cancellation failure must abort the transaction.');
    } catch (RuntimeException $exception) {
        $this->assertSame(
            'Simulated cancellation failure.',
            $exception->getMessage(),
        );
    }

    $this->assertTrue($failed);
    $this->assertSame('confirmed', $booking->fresh()->status);
    $this->assertNull($booking->fresh()->ended_reason);
    $this->assertSame('confirmed', $visit->fresh()->status);
    $this->assertSame(1, $visit->fresh()->lock_version);
    $this->assertSame('assigned', $workOrder->fresh()->status);
    $this->assertDatabaseCount('visit_bookings', 1);
    $this->assertDatabaseCount('operation_history', $historyCount);

    $cancelled = $service->cancel(
        $coordinator,
        $booking->id,
        $payload,
    );

    $this->assertSame('cancelled', $cancelled->status);
    $this->assertSame('cancelled', $visit->fresh()->status);
    $this->assertSame(2, $visit->fresh()->lock_version);
    $this->assertDatabaseCount('visit_bookings', 1);
    $this->assertDatabaseCount(
        'operation_history',
        $historyCount + 1,
    );
}

public static function cancellationFailurePoints(): array
{
    return [
        'booking update' => ['update `visit_bookings`'],
        'visit update' => ['update `work_visits`'],
        'audit insert' => ['insert into `operation_history`'],
    ];
}
}