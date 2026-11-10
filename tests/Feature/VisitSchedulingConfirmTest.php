<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\VisitBooking;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use App\Services\VisitSchedulingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use RuntimeException;

class VisitSchedulingConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_books_all_members_without_changing_wo_membership(): void
    {
        $technicians = User::factory()->count(2)->create([
            'role' => 'teknisi',
        ]);

        [$coordinator, $workOrder, $visit] = $this->context(
            $technicians->all(),
        );

        $confirmed = app(VisitSchedulingService::class)->confirm(
            $coordinator,
            $visit->id,
            $this->payload($technicians->modelKeys()),
        );

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(1, $confirmed->lock_version);
        $this->assertSame(
            $technicians->modelKeys(),
            $confirmed->bookings()
                ->orderBy('technician_id')
                ->pluck('technician_id')
                ->all(),
        );

        foreach ($confirmed->bookings as $booking) {
            $this->assertSame('confirmed', $booking->status);
            $this->assertSame(
                $coordinator->id,
                $booking->confirmed_by,
            );
            $this->assertNotNull($booking->confirmed_at);
            $this->assertNull($booking->actual_start_at);
            $this->assertNull($booking->actual_end_at);
        }

        $this->assertSame('assigned', $workOrder->fresh()->status);
        $this->assertSame(2, $workOrder->technicians()->count());
        $this->assertDatabaseCount('visit_bookings', 2);

        $history = OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->sole();

        $this->assertSame('work_visit', $history->subject_type);
        $this->assertSame($visit->id, $history->subject_id);
        $this->assertSame('draft', $history->from_state);
        $this->assertSame('confirmed', $history->to_state);
    }

    public function test_one_cross_wo_conflict_rejects_the_entire_group(): void
    {
        $busy = User::factory()->create(['role' => 'teknisi']);
        $free = User::factory()->create(['role' => 'teknisi']);

        [$firstCoordinator, , $firstVisit] = $this->context([$busy]);
        [$secondCoordinator, $secondWorkOrder, $secondVisit] =
            $this->context([$free, $busy]);

        $service = app(VisitSchedulingService::class);

        $service->confirm(
            $firstCoordinator,
            $firstVisit->id,
            $this->payload([$busy->id]),
        );

        try {
            $service->confirm(
                $secondCoordinator,
                $secondVisit->id,
                $this->payload([$free->id, $busy->id]),
            );

            $this->fail('A conflicting group must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertArrayHasKey(
                'technician_ids',
                $exception->errors(),
            );
        }

        $this->assertSame('draft', $secondVisit->fresh()->status);
        $this->assertSame(0, $secondVisit->fresh()->lock_version);
        $this->assertSame(0, $secondVisit->bookings()->count());

        $this->assertDatabaseCount('visit_bookings', 1);
        $this->assertSame(
            0,
            VisitBooking::query()
                ->where('technician_id', $free->id)
                ->count(),
        );

        $this->assertSame(
            'confirmed',
            $firstVisit->fresh()->status,
        );
        $this->assertSame(
            'assigned',
            $secondWorkOrder->fresh()->status,
        );
        $this->assertSame(
            2,
            $secondWorkOrder->technicians()->count(),
        );
        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'visit.confirm')
                ->count(),
        );
    }

    /**
     * @param list<User> $technicians
     * @param array<string, string> $schedule
     * @return array{User, WorkOrder, WorkVisit}
     */
    private function context(
        array $technicians,
        array $schedule = [],
    ): array {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $customer = Customer::query()->create([
            'name' => 'PT Confirm Testing',
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
                'manual_reason' => 'Permintaan operasional pengujian',
                'initial_scope' => [
                    'description' => 'Pulihkan tampilan kamera',
                    'acceptance_criteria' => 'Live feed tampil pada NVR',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Pemeriksaan kamera',
                'description' => 'Kamera tidak menampilkan gambar',
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
                ...$schedule,
            ],
        );

        return [$coordinator, $workOrder, $visit];
    }

    /** @param list<int> $technicianIds */
    private function payload(array $technicianIds): array
    {
        return [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => 0,
            'technician_ids' => $technicianIds,
        ];
    }

    public function test_non_member_rejects_the_entire_confirmation(): void
{
    $member = User::factory()->create(['role' => 'teknisi']);
    $outsider = User::factory()->create(['role' => 'teknisi']);

    [$coordinator, , $visit] = $this->context([$member]);

    try {
        app(VisitSchedulingService::class)->confirm(
            $coordinator,
            $visit->id,
            $this->payload([$member->id, $outsider->id]),
        );

        $this->fail('Non-member must be rejected.');
    } catch (ValidationException $exception) {
        $this->assertSame(422, $exception->status);
        $this->assertArrayHasKey(
            'technician_ids',
            $exception->errors(),
        );
    }

    $this->assertSame('draft', $visit->fresh()->status);
    $this->assertSame(0, $visit->fresh()->lock_version);
    $this->assertDatabaseCount('visit_bookings', 0);
    $this->assertSame(
        0,
        OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->count(),
    );
}

#[DataProvider('ineligibleTechnicians')]
public function test_member_eligibility_is_rechecked_before_confirmation(
    array $attributes,
): void {
    $technician = User::factory()->create(['role' => 'teknisi']);

    [$coordinator, $workOrder, $visit] = $this->context([$technician]);

    DB::table('users')
        ->where('id', $technician->id)
        ->update($attributes);

    try {
        app(VisitSchedulingService::class)->confirm(
            $coordinator,
            $visit->id,
            $this->payload([$technician->id]),
        );

        $this->fail('Ineligible member must be rejected.');
    } catch (ValidationException $exception) {
        $this->assertSame(422, $exception->status);
        $this->assertArrayHasKey(
            'technician_ids',
            $exception->errors(),
        );
    }

    $this->assertDatabaseCount('visit_bookings', 0);
    $this->assertSame('draft', $visit->fresh()->status);
    $this->assertSame(0, $visit->fresh()->lock_version);
    $this->assertSame(1, $workOrder->technicians()->count());
}

public static function ineligibleTechnicians(): array
{
    return [
        'role changed' => [['role' => 'admin']],
        'missing role' => [['role' => null]],
        'inactive' => [['is_active' => false]],
        'pending approval' => [['approval_status' => 'pending']],
        'rejected approval' => [['approval_status' => 'rejected']],
    ];
}

public function test_adjacent_visits_for_same_technician_are_allowed(): void
{
    $technician = User::factory()->create(['role' => 'teknisi']);

    [$firstCoordinator, , $firstVisit] = $this->context([$technician]);

    [$secondCoordinator, , $secondVisit] = $this->context(
        [$technician],
        [
            'starts_at' => '2026-11-12T11:00+07:00',
            'ends_at' => '2026-11-12T13:00+07:00',
        ],
    );

    $service = app(VisitSchedulingService::class);

    $service->confirm(
        $firstCoordinator,
        $firstVisit->id,
        $this->payload([$technician->id]),
    );

    $service->confirm(
        $secondCoordinator,
        $secondVisit->id,
        $this->payload([$technician->id]),
    );

    $this->assertSame('confirmed', $firstVisit->fresh()->status);
    $this->assertSame('confirmed', $secondVisit->fresh()->status);
    $this->assertSame(
        $firstVisit->fresh()->ends_at->getTimestamp(),
        $secondVisit->fresh()->starts_at->getTimestamp(),
    );
    $this->assertDatabaseCount('visit_bookings', 2);
}

public function test_replay_normalizes_technician_order_and_integer_strings(): void
{
    $technicians = User::factory()->count(2)->create([
        'role' => 'teknisi',
    ]);

    [$coordinator, , $visit] = $this->context($technicians->all());

    $ids = $technicians->modelKeys();
    $payload = $this->payload(array_reverse($ids));
    $service = app(VisitSchedulingService::class);

    $first = $service->confirm(
        $coordinator,
        $visit->id,
        $payload,
    );

    $replay = $service->confirm(
        $coordinator,
        $visit->id,
        [
            ...$payload,
            'technician_ids' => array_map('strval', $ids),
        ],
    );

    $this->assertSame($first->id, $replay->id);
    $this->assertSame(1, $replay->lock_version);
    $this->assertDatabaseCount('visit_bookings', 2);
    $this->assertSame(
        1,
        OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->count(),
    );
}

public function test_new_command_with_stale_version_is_rejected(): void
{
    $technician = User::factory()->create(['role' => 'teknisi']);

    [$coordinator, , $visit] = $this->context([$technician]);

    $service = app(VisitSchedulingService::class);

    $service->confirm(
        $coordinator,
        $visit->id,
        $this->payload([$technician->id]),
    );

    try {
        $service->confirm(
            $coordinator,
            $visit->id,
            $this->payload([$technician->id]),
        );

        $this->fail('A new command with stale version must be rejected.');
    } catch (ValidationException $exception) {
        $this->assertSame(409, $exception->status);
        $this->assertSame(
            ['VISIT_VERSION_CONFLICT'],
            $exception->errors()['expected_visit_version'],
        );
    }

    $this->assertSame(1, $visit->fresh()->lock_version);
    $this->assertDatabaseCount('visit_bookings', 1);
    $this->assertSame(
        1,
        OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->count(),
    );
}

public function test_same_key_cannot_be_reused_with_different_participants(): void
{
    $technicians = User::factory()->count(2)->create([
        'role' => 'teknisi',
    ]);

    [$coordinator, , $visit] = $this->context($technicians->all());

    $ids = $technicians->modelKeys();
    $payload = $this->payload($ids);
    $service = app(VisitSchedulingService::class);

    $service->confirm($coordinator, $visit->id, $payload);

    try {
        $service->confirm(
            $coordinator,
            $visit->id,
            [...$payload, 'technician_ids' => [$ids[0]]],
        );

        $this->fail('Changed participants must not reuse a committed key.');
    } catch (ValidationException $exception) {
        $this->assertSame(409, $exception->status);
        $this->assertSame(
            ['COMMAND_KEY_REUSED'],
            $exception->errors()['command_key'],
        );
    }

    $this->assertDatabaseCount('visit_bookings', 2);
    $this->assertSame(1, $visit->fresh()->lock_version);
    $this->assertSame(
        1,
        OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->count(),
    );
}

#[DataProvider('confirmationFailurePoints')]
public function test_failed_confirmation_rolls_back_and_can_be_retried(
    string $sqlPrefix,
    int $failureAt,
): void {
    $technicians = User::factory()->count(2)->create([
        'role' => 'teknisi',
    ]);

    [$coordinator, $workOrder, $visit] = $this->context(
        $technicians->all(),
    );

    $payload = $this->payload($technicians->modelKeys());
    $historyCount = OperationHistory::query()->count();
    $seen = 0;
    $failed = false;

    DB::connection()->beforeExecuting(
        function (string $sql) use (
            $sqlPrefix,
            $failureAt,
            &$seen,
            &$failed,
        ): void {
            if (! $failed && str_starts_with($sql, $sqlPrefix)) {
                $seen++;

                if ($seen === $failureAt) {
                    $failed = true;

                    throw new RuntimeException(
                        'Simulated confirmation failure.',
                    );
                }
            }
        },
    );

    $service = app(VisitSchedulingService::class);

    try {
        $service->confirm(
            $coordinator,
            $visit->id,
            $payload,
        );

        $this->fail('Confirmation failure must abort the transaction.');
    } catch (RuntimeException $exception) {
        $this->assertSame(
            'Simulated confirmation failure.',
            $exception->getMessage(),
        );
    }

    $this->assertTrue($failed);
    $this->assertDatabaseCount('visit_bookings', 0);
    $this->assertDatabaseCount('operation_history', $historyCount);
    $this->assertSame('draft', $visit->fresh()->status);
    $this->assertSame(0, $visit->fresh()->lock_version);
    $this->assertSame('assigned', $workOrder->fresh()->status);
    $this->assertSame(2, $workOrder->technicians()->count());

    $confirmed = $service->confirm(
        $coordinator,
        $visit->id,
        $payload,
    );

    $this->assertSame($visit->id, $confirmed->id);
    $this->assertSame('confirmed', $confirmed->status);
    $this->assertSame(1, $confirmed->lock_version);
    $this->assertDatabaseCount('visit_bookings', 2);
    $this->assertDatabaseCount(
        'operation_history',
        $historyCount + 1,
    );
    $this->assertSame(
        1,
        OperationHistory::query()
            ->where('action', 'visit.confirm')
            ->count(),
    );
}

public static function confirmationFailurePoints(): array
{
    return [
        'second booking insert' => [
            'insert into `visit_bookings`',
            2,
        ],
        'visit status update' => [
            'update `work_visits`',
            1,
        ],
        'audit insert' => [
            'insert into `operation_history`',
            1,
        ],
    ];
}
}
