<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use App\Services\VisitBookingExecutionService;
use App\Services\VisitSchedulingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitBookingExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_can_start_and_complete_own_booking(): void
    {
        [, $workOrder, $visit, $technician] = $this->context();
        $booking = $visit->bookings()->sole();
        $service = app(VisitBookingExecutionService::class);

        $this->travelTo(Carbon::parse('2026-11-14 02:07:00', 'UTC'));

        $started = $service->start($technician, $booking->id, [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->lock_version,
            'expected_booking_status' => 'confirmed',
        ]);

        $this->assertSame('in_progress', $started->status);
        $this->assertSame(
            '2026-11-14 02:07:00',
            $started->actual_start_at->format('Y-m-d H:i:s'),
        );
        $this->assertNull($started->actual_end_at);
        $this->assertSame('in_progress', $visit->fresh()->status);
        $this->assertSame(2, $visit->fresh()->lock_version);
        $this->assertSame('in_progress', $workOrder->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-14 03:40:00', 'UTC'));

        $completed = $service->complete($technician, $booking->id, [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->fresh()->lock_version,
            'expected_booking_status' => 'in_progress',
        ]);

        $this->assertSame('completed', $completed->status);
        $this->assertSame(
            '2026-11-14 02:07:00',
            $completed->actual_start_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-11-14 03:40:00',
            $completed->actual_end_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame(3, $visit->fresh()->lock_version);

        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertNull($workOrder->fresh()->completed_at);
        $this->assertNull($workOrder->fresh()->verified_by);

        $this->assertDatabaseHas('work_order_updates', [
            'work_order_id' => $workOrder->id,
            'changed_by' => $technician->id,
            'from_status' => 'assigned',
            'to_status' => 'in_progress',
        ]);

        $history = OperationHistory::query()
            ->where('action', 'visit.booking.complete')
            ->sole();

        $this->assertSame($booking->id, $history->subject_id);
        $this->assertSame($technician->id, $history->actor_id);
        $this->assertSame('in_progress', $history->from_state);
        $this->assertSame('completed', $history->to_state);
    }

    public function test_identical_retries_preserve_times_and_create_history_once(): void
    {
        [, $workOrder, $visit, $technician] = $this->context();
        $booking = $visit->bookings()->sole();
        $service = app(VisitBookingExecutionService::class);

        $startPayload = [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->lock_version,
            'expected_booking_status' => 'confirmed',
        ];

        $service->start($technician, $booking->id, $startPayload);
        $startTime = $booking->fresh()->getRawOriginal('actual_start_at');

        $this->travel(1)->minutes();

        $service->start($technician, $booking->id, $startPayload);

        $this->assertSame(
            $startTime,
            $booking->fresh()->getRawOriginal('actual_start_at'),
        );
        $this->assertSame(2, $visit->fresh()->lock_version);

        $completePayload = [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->fresh()->lock_version,
            'expected_booking_status' => 'in_progress',
        ];

        $service->complete($technician, $booking->id, $completePayload);
        $endTime = $booking->fresh()->getRawOriginal('actual_end_at');

        $this->travel(1)->minutes();

        $service->complete($technician, $booking->id, $completePayload);

        $this->assertSame(
            $endTime,
            $booking->fresh()->getRawOriginal('actual_end_at'),
        );
        $this->assertSame(3, $visit->fresh()->lock_version);
        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'visit.booking.start')
                ->count(),
        );
        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'visit.booking.complete')
                ->count(),
        );
        $this->assertSame(
            1,
            $workOrder->updates()->where('to_status', 'in_progress')->count(),
        );
    }

    public function test_another_wo_member_cannot_start_someone_elses_booking(): void
    {
        [, $workOrder, $visit] = $this->context();
        $booking = $visit->bookings()->sole();

        $otherTechnician = User::factory()->create([
            'role' => 'teknisi',
        ]);

        $workOrder->technicians()->attach($otherTechnician, [
            'assigned_at' => now(),
        ]);

        try {
            app(VisitBookingExecutionService::class)->start(
                $otherTechnician,
                $booking->id,
                [
                    'command_key' => (string) Str::uuid(),
                    'expected_visit_version' => $visit->lock_version,
                    'expected_booking_status' => 'confirmed',
                ],
            );

            $this->fail('Teknisi lain harus ditolak.');
        } catch (AuthorizationException) {
            $this->assertSame('confirmed', $booking->fresh()->status);
            $this->assertNull($booking->fresh()->actual_start_at);
            $this->assertSame('confirmed', $visit->fresh()->status);
            $this->assertSame(1, $visit->fresh()->lock_version);
            $this->assertSame('assigned', $workOrder->fresh()->status);

            $this->assertDatabaseMissing('operation_history', [
                'action' => 'visit.booking.start',
            ]);
        }
    }

    public function test_http_start_and_complete_support_identical_retries(): void
    {
        [, $workOrder, $visit, $technician] = $this->context();
        $booking = $visit->bookings()->sole();

        $startUrl = route(
            'work-orders.visits.bookings.start',
            [$workOrder, $visit, $booking],
        );

        $completeUrl = route(
            'work-orders.visits.bookings.complete',
            [$workOrder, $visit, $booking],
        );

        $startPayload = [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->lock_version,
            'expected_booking_status' => 'confirmed',
        ];

        $this->actingAs($technician)
            ->post($startUrl, $startPayload)
            ->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->post($startUrl, $startPayload)
            ->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->assertSame('in_progress', $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->actual_start_at);
        $this->assertSame(2, $visit->fresh()->lock_version);

        $completePayload = [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->fresh()->lock_version,
            'expected_booking_status' => 'in_progress',
        ];

        $this->post($completeUrl, $completePayload)
            ->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->post($completeUrl, $completePayload)
            ->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->actual_end_at);
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame(3, $visit->fresh()->lock_version);
        $this->assertSame('in_progress', $workOrder->fresh()->status);

        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'visit.booking.start')
                ->count(),
        );

        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'visit.booking.complete')
                ->count(),
        );
    }

    #[DataProvider('executionActions')]
    public function test_http_execution_rejects_unauthorized_users(string $action): void
    {
        [$coordinator, $workOrder, $visit] = $this->context();
        $booking = $visit->bookings()->sole();

        $url = route(
            'work-orders.visits.bookings.'.$action,
            [$workOrder, $visit, $booking],
        );

        $payload = [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->lock_version,
            'expected_booking_status' => 'confirmed',
        ];

        $this->post($url, $payload)->assertRedirectToRoute('login');

        $this->actingAs($coordinator)
            ->post($url, $payload)
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post($url, $payload)
            ->assertForbidden();

        $otherMember = User::factory()->create(['role' => 'teknisi']);

        $workOrder->technicians()->attach($otherMember, [
            'assigned_at' => now(),
        ]);

        $this->actingAs($otherMember)
            ->post($url, $payload)
            ->assertForbidden();

        $outsider = User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($outsider)
            ->post($url, $payload)
            ->assertNotFound();

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->actual_start_at);
        $this->assertSame('assigned', $workOrder->fresh()->status);

        $this->assertDatabaseMissing('operation_history', [
            'action' => 'visit.booking.'.$action,
        ]);
    }

    #[DataProvider('executionActions')]
    public function test_http_execution_checks_visit_and_booking_parents(string $action): void
    {
        [, $workOrder, $visit, $technician] = $this->context();
        [, , $otherVisit] = $this->context();

        $booking = $visit->bookings()->sole();
        $otherBooking = $otherVisit->bookings()->sole();

        $payload = [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => $visit->lock_version,
            'expected_booking_status' => 'confirmed',
        ];

        $this->actingAs($technician)
            ->post(
                route(
                    'work-orders.visits.bookings.'.$action,
                    [$workOrder, $otherVisit, $otherBooking],
                ),
                $payload,
            )
            ->assertNotFound();

        $this->post(
            route(
                'work-orders.visits.bookings.'.$action,
                [$workOrder, $visit, $otherBooking],
            ),
            $payload,
        )->assertNotFound();

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('confirmed', $otherBooking->fresh()->status);

        $this->assertDatabaseMissing('operation_history', [
            'action' => 'visit.booking.'.$action,
        ]);
    }

    public function test_http_start_rejects_missing_input_and_stale_version(): void
    {
        [, $workOrder, $visit, $technician] = $this->context();
        $booking = $visit->bookings()->sole();

        $url = route(
            'work-orders.visits.bookings.start',
            [$workOrder, $visit, $booking],
        );

        $this->actingAs($technician)
            ->postJson($url, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'command_key',
                'expected_visit_version',
                'expected_booking_status',
            ]);

        $this->postJson($url, [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => 0,
            'expected_booking_status' => 'confirmed',
        ])
            ->assertStatus(409)
            ->assertJsonPath(
                'errors.expected_visit_version.0',
                'VISIT_VERSION_CONFLICT',
            );

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->actual_start_at);
        $this->assertSame('assigned', $workOrder->fresh()->status);

        $this->assertDatabaseMissing('operation_history', [
            'action' => 'visit.booking.start',
        ]);
    }

    public function test_index_exposes_execution_transitions_and_actual_times_without_offering_reschedule_after_start(): void
    {
        [$coordinator, $workOrder, $visit, $technician] = $this->context();
        $booking = $visit->bookings()->sole();
        $indexUrl = route('work-orders.visits.index', $workOrder);

        $this->actingAs($technician)->get($indexUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visits.data.0.bookings.0.execution_action', 'start')
                ->where('visits.data.0.bookings.0.execution_url', route('work-orders.visits.bookings.start', [$workOrder, $visit, $booking]))
                ->where('visits.data.0.bookings.0.execution_command_key', fn ($key) => Str::isUuid($key))
                ->where('visits.data.0.bookings.0.actual_start_at', null)
                ->where('visits.data.0.bookings.0.actual_end_at', null)->etc());
        $this->actingAs($coordinator)->get($indexUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visits.data.0.reschedule_url', route('work-orders.visits.reschedule', [$workOrder, $visit]))->etc());

        $this->travelTo(Carbon::parse('2026-11-14 02:07:00', 'UTC'));
        $this->actingAs($technician)->post(route('work-orders.visits.bookings.start', [$workOrder, $visit, $booking]), [
            'command_key' => (string) Str::uuid(), 'expected_visit_version' => 1, 'expected_booking_status' => 'confirmed',
        ])->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->get($indexUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('visits.data.0.bookings.0.execution_action', 'complete')
            ->where('visits.data.0.bookings.0.execution_url', route('work-orders.visits.bookings.complete', [$workOrder, $visit, $booking]))
            ->where('visits.data.0.bookings.0.execution_command_key', fn ($key) => Str::isUuid($key))
            ->where('visits.data.0.bookings.0.actual_start_at', '2026-11-14T02:07:00+00:00')
            ->where('visits.data.0.bookings.0.actual_end_at', null)->etc());
        $this->actingAs($coordinator)->get($indexUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('visits.data.0.reschedule_url', null)
            ->where('visits.data.0.reschedule_command_key', null)->etc());

        $this->travelTo(Carbon::parse('2026-11-14 03:40:00', 'UTC'));
        $this->actingAs($technician)->post(route('work-orders.visits.bookings.complete', [$workOrder, $visit, $booking]), [
            'command_key' => (string) Str::uuid(), 'expected_visit_version' => 2, 'expected_booking_status' => 'in_progress',
        ])->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->get($indexUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('visits.data.0.bookings.0.execution_action', null)
            ->where('visits.data.0.bookings.0.execution_url', null)
            ->where('visits.data.0.bookings.0.execution_command_key', null)
            ->where('visits.data.0.bookings.0.actual_start_at', '2026-11-14T02:07:00+00:00')
            ->where('visits.data.0.bookings.0.actual_end_at', '2026-11-14T03:40:00+00:00')->etc());
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame('in_progress', $workOrder->fresh()->status);
    }

    public function test_index_hides_execution_from_coordinator_and_other_work_order_member(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $otherMember = User::factory()->create(['role' => 'teknisi']);
        $workOrder->technicians()->attach($otherMember, ['assigned_at' => now()]);

        foreach ([$coordinator, $otherMember] as $reader) {
            $this->actingAs($reader)->get(route('work-orders.visits.index', $workOrder))->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('visits.data.0.bookings.0.execution_action', null)
                    ->where('visits.data.0.bookings.0.execution_url', null)
                    ->where('visits.data.0.bookings.0.execution_command_key', null)->etc());
        }
    }

    #[DataProvider('additionalStartStates')]
    public function test_index_offers_start_for_remaining_supported_states(string $workOrderStatus, string $visitStatus): void
    {
        [, $workOrder, $visit, $technician] = $this->context();
        $workOrder->forceFill(['status' => $workOrderStatus])->save();
        $visit->forceFill(['status' => $visitStatus])->save();

        $this->actingAs($technician)->get(route('work-orders.visits.index', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visits.data.0.bookings.0.execution_action', 'start')
                ->where('visits.data.0.bookings.0.execution_url', route('work-orders.visits.bookings.start', [$workOrder, $visit, $visit->bookings()->sole()]))
                ->where('visits.data.0.bookings.0.execution_command_key', fn ($key) => Str::isUuid($key))->etc());
    }

    public static function additionalStartStates(): array
    {
        return [
            'in progress WO' => ['in_progress', 'confirmed'],
            'rework WO' => ['rework', 'confirmed'],
            'in progress visit' => ['in_progress', 'in_progress'],
        ];
    }

    #[DataProvider('ineligibleOwners')]
    public function test_ineligible_booking_owner_cannot_read_execution_props(array $attributes): void
    {
        [, $workOrder, , $technician] = $this->context();
        $technician->forceFill($attributes)->save();

        $this->actingAs($technician)->get(route('work-orders.visits.index', $workOrder))->assertRedirectToRoute('login');
    }

    public static function ineligibleOwners(): array
    {
        return [
            'inactive' => [['is_active' => false]],
            'unapproved' => [['approval_status' => 'pending']],
        ];
    }

    public function test_index_hides_execution_when_required_scope_is_missing(): void
    {
        [, $workOrder, , $technician] = $this->context();
        $workOrder->scopeItems()->update(['is_required' => false]);

        $this->actingAs($technician)->get(route('work-orders.visits.index', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visits.data.0.bookings.0.execution_action', null)
                ->where('visits.data.0.bookings.0.execution_url', null)
                ->where('visits.data.0.bookings.0.execution_command_key', null)->etc());
    }

    /**
     * @param  array<string, mixed>  $workOrderState
     * @param  array<string, mixed>  $visitState
     * @param  array<string, mixed>  $bookingState
     */
    #[DataProvider('hiddenExecutionStates')]
    public function test_index_hides_execution_outside_supported_states(array $workOrderState, array $visitState, array $bookingState): void
    {
        [, $workOrder, $visit, $technician] = $this->context();
        $workOrder->forceFill($workOrderState)->save();
        $visit->forceFill($visitState)->save();
        $visit->bookings()->sole()->forceFill($bookingState)->save();

        $this->actingAs($technician)->get(route('work-orders.visits.index', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visits.data.0.bookings.0.execution_action', null)
                ->where('visits.data.0.bookings.0.execution_url', null)
                ->where('visits.data.0.bookings.0.execution_command_key', null)->etc());
    }

    public static function hiddenExecutionStates(): array
    {
        return [
            'legacy WO' => [['origin_kind' => 'legacy_unknown'], [], []],
            'missing acceptance context' => [['acceptance_context_ref' => null], [], []],
            'WO on hold' => [['status' => 'on_hold'], [], []],
            'cancelled visit' => [[], ['status' => 'cancelled'], []],
            'cancelled booking' => [[], [], ['status' => 'cancelled']],
            'confirmed booking with actual start' => [[], [], ['actual_start_at' => '2026-11-14 02:07:00']],
            'confirmed booking with actual end' => [[], [], ['actual_start_at' => '2026-11-14 02:07:00', 'actual_end_at' => '2026-11-14 03:40:00']],
            'running booking without actual start' => [['status' => 'in_progress'], ['status' => 'in_progress'], ['status' => 'in_progress']],
            'running booking on assigned WO' => [[], ['status' => 'in_progress'], ['status' => 'in_progress', 'actual_start_at' => '2026-11-14 02:07:00']],
            'running booking on confirmed visit' => [['status' => 'in_progress'], [], ['status' => 'in_progress', 'actual_start_at' => '2026-11-14 02:07:00']],
            'running booking with actual end' => [['status' => 'in_progress'], ['status' => 'in_progress'], ['status' => 'in_progress', 'actual_start_at' => '2026-11-14 02:07:00', 'actual_end_at' => '2026-11-14 03:40:00']],
        ];
    }

    public static function executionActions(): array
    {
        return [
            'start' => ['start'],
            'complete' => ['complete'],
        ];
    }

    /** @return array{User, WorkOrder, WorkVisit, User} */
    private function context(): array
    {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $technician = User::factory()->create([
            'role' => 'teknisi',
        ]);

        $customer = Customer::query()->create([
            'name' => 'Customer Execution Testing',
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
                'manual_reason' => 'Permintaan pengujian',
                'initial_scope' => [
                    'description' => 'Pulihkan kamera',
                    'acceptance_criteria' => 'Live feed tampil',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Pemeriksaan kamera',
                'description' => 'Kamera offline',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ],
        );

        $workOrder->technicians()->attach($technician, [
            'assigned_at' => now(),
        ]);

        $workOrder->forceFill(['status' => 'assigned'])->save();

        $scheduling = app(VisitSchedulingService::class);

        $visit = $scheduling->createDraft($coordinator, $workOrder->id, [
            'command_key' => (string) Str::uuid(),
            'starts_at' => '2026-11-14T09:00+07:00',
            'ends_at' => '2026-11-14T11:00+07:00',
            'zone_name' => 'Asia/Jakarta',
        ]);

        $visit = $scheduling->confirm($coordinator, $visit->id, [
            'command_key' => (string) Str::uuid(),
            'expected_visit_version' => 0,
            'technician_ids' => [$technician->id],
        ]);

        return [$coordinator, $workOrder, $visit, $technician];
    }
}
