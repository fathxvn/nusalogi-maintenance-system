<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use App\Services\VisitSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkVisitHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_exposes_only_eligible_members_and_server_authorized_actions(): void
    {
        [$coordinator, $workOrder, $technician] = $this->context();
        $inactive = User::factory()->create(['role' => 'teknisi', 'is_active' => false]);
        $workOrder->technicians()->attach($inactive, ['assigned_at' => now()]);
        $visit = $this->draft($coordinator, $workOrder);

        $this->actingAs($coordinator)->get(route('work-orders.visits.index', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('work-orders/visits/index')
                ->where('workOrder.id', $workOrder->id)->has('members', 2)
                ->where('technicians', [['id' => $technician->id, 'name' => $technician->name]])
                ->where('visits.data.0.starts_at', '2026-11-12T02:00:00+00:00')
                ->where('visits.data.0.confirm_url', route('work-orders.visits.confirm', [$workOrder, $visit]))
                ->where('links.create', route('work-orders.visits.create', $workOrder))->etc());

        $this->actingAs($coordinator)->get(route('work-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('visitLinks.'.$workOrder->id, route('work-orders.visits.index', $workOrder))->etc());
    }

    #[DataProvider('readRoles')]
    public function test_readers_without_scheduling_authority_receive_no_write_actions(string $role): void
    {
        [$coordinator, $workOrder, $technician] = $this->context();
        $visit = $this->draft($coordinator, $workOrder);
        app(VisitSchedulingService::class)->confirm($coordinator, $visit->id, $this->confirmPayload($technician));
        $reader = $role === 'teknisi' ? $technician : User::factory()->create(['role' => $role]);

        $this->actingAs($reader)->get(route('work-orders.visits.index', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.create', null)->where('technicians', [])
                ->where('visits.data.0.confirm_url', null)->where('visits.data.0.command_key', null)
                ->where('visits.data.0.bookings.0.cancel_url', null)->where('visits.data.0.bookings.0.command_key', null)->etc());
        $this->actingAs($reader)->get(route('work-orders.visits.create', $workOrder))->assertForbidden();
        $this->post(route('work-orders.visits.store', $workOrder), $this->draftPayload())->assertForbidden();
        $this->post(route('work-orders.visits.confirm', [$workOrder, $visit]), $this->confirmPayload($technician))->assertForbidden();
        $this->post(route('work-orders.visits.bookings.cancel', [$workOrder, $visit, $visit->bookings()->sole()]), [])->assertForbidden();
        $this->assertDatabaseCount('visit_bookings', 1);
    }

    public static function readRoles(): array
    {
        return ['admin' => ['admin'], 'member technician' => ['teknisi']];
    }

    public function test_membership_controls_read_access_and_revocation_takes_effect(): void
    {
        [$coordinator, $workOrder, $technician] = $this->context();
        $this->draft($coordinator, $workOrder);
        $outsider = User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($outsider)->get(route('work-orders.visits.index', $workOrder))->assertNotFound();
        $this->actingAs($technician)->get(route('work-orders.visits.index', $workOrder))->assertOk();
        $workOrder->technicians()->detach($technician);
        $this->get(route('work-orders.visits.index', $workOrder))->assertNotFound();
    }

    public function test_guest_and_disabled_accounts_cannot_access_scheduling(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $this->get(route('work-orders.visits.index', $workOrder))->assertRedirectToRoute('login');
        $this->post(route('work-orders.visits.store', $workOrder), $this->draftPayload())->assertRedirectToRoute('login');
        $coordinator->forceFill(['is_active' => false])->save();
        $this->actingAs($coordinator)->get(route('work-orders.visits.index', $workOrder))->assertRedirectToRoute('login');
        $this->assertDatabaseCount('work_visits', 0);
    }

    public function test_legacy_wo_is_readable_but_cannot_create_an_m1_visit(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $workOrder->forceFill(['origin_kind' => 'legacy_unknown'])->save();

        $this->actingAs($coordinator)->get(route('work-orders.visits.index', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.create', null)->where('unavailableReason', fn ($value) => is_string($value))->etc());
        $this->get(route('work-orders.visits.create', $workOrder))->assertNotFound();
        $this->postJson(route('work-orders.visits.store', $workOrder), $this->draftPayload())->assertStatus(409)
            ->assertJsonValidationErrors(['work_order_id']);
        $this->assertDatabaseCount('work_visits', 0);
    }

    public function test_create_form_and_draft_post_use_the_existing_service_idempotently(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $this->actingAs($coordinator)->get(route('work-orders.visits.create', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('work-orders/visits/create')
                ->where('commandKey', fn ($key) => Str::isUuid($key))->where('links.store', route('work-orders.visits.store', $workOrder))->etc());
        $payload = $this->draftPayload();
        $url = route('work-orders.visits.store', $workOrder);
        $this->post($url, [...$payload, 'status' => 'confirmed', 'technician_ids' => [999]])->assertRedirectToRoute('work-orders.visits.index', $workOrder);
        $this->post($url, $payload)->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->assertDatabaseCount('work_visits', 1);
        $this->assertDatabaseCount('visit_bookings', 0);
        $this->assertDatabaseHas('work_visits', ['work_order_id' => $workOrder->id, 'status' => 'draft', 'starts_at' => '2026-11-12 02:00:00']);
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.create_draft')->count());
        $this->assertSame('assigned', $workOrder->fresh()->status);
    }

    public function test_http_validation_returns_to_the_form_without_saving_a_draft(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $page = route('work-orders.visits.create', $workOrder);
        $this->actingAs($coordinator)->from($page)->post(route('work-orders.visits.store', $workOrder), [
            ...$this->draftPayload(), 'ends_at' => '2026-11-12T08:00+07:00',
        ])->assertRedirect($page)->assertSessionHasErrors(['ends_at' => 'Waktu selesai harus setelah waktu mulai.']);
        $this->assertDatabaseCount('work_visits', 0);
    }

    public function test_confirm_and_cancel_http_actions_preserve_membership_and_wo_state(): void
    {
        [$coordinator, $workOrder, $technician] = $this->context();
        $visit = $this->draft($coordinator, $workOrder);
        $payload = $this->confirmPayload($technician);
        $url = route('work-orders.visits.confirm', [$workOrder, $visit]);
        $this->actingAs($coordinator)->post($url, $payload)->assertRedirectToRoute('work-orders.visits.index', $workOrder);
        $this->post($url, $payload)->assertRedirectToRoute('work-orders.visits.index', $workOrder);
        $booking = $visit->bookings()->sole();
        $this->assertDatabaseCount('visit_bookings', 1);
        $cancel = ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 1, 'expected_booking_status' => 'confirmed', 'reason' => 'Akses site ditunda'];
        $cancelUrl = route('work-orders.visits.bookings.cancel', [$workOrder, $visit, $booking]);
        $this->post($cancelUrl, $cancel)->assertRedirectToRoute('work-orders.visits.index', $workOrder);
        $this->post($cancelUrl, $cancel)->assertRedirectToRoute('work-orders.visits.index', $workOrder);

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('Akses site ditunda', $booking->fresh()->ended_reason);
        $this->assertNull($booking->fresh()->actual_start_at);
        $this->assertSame('cancelled', $visit->fresh()->status);
        $this->assertSame('assigned', $workOrder->fresh()->status);
        $this->assertSame([$technician->id], $workOrder->technicians()->pluck('users.id')->all());
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.confirm')->count());
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.booking.cancel')->count());
    }

    public function test_nested_ids_cannot_target_another_work_order_or_visit(): void
    {
        [$coordinator, $workOrder] = $this->context();
        [, $other, $technician] = $this->context($coordinator);
        $visit = $this->draft($coordinator, $workOrder);
        $otherVisit = $this->draft($coordinator, $other);
        app(VisitSchedulingService::class)->confirm($coordinator, $otherVisit->id, $this->confirmPayload($technician));
        $booking = $otherVisit->bookings()->sole();

        $this->actingAs($coordinator)->post(route('work-orders.visits.confirm', [$workOrder, $otherVisit]), [])->assertNotFound();
        $this->post(route('work-orders.visits.bookings.cancel', [$workOrder, $otherVisit, $booking]), [])->assertNotFound();
        $this->post(route('work-orders.visits.bookings.cancel', [$workOrder, $visit, $booking]), [])->assertNotFound();
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('draft', $visit->fresh()->status);
        $this->assertSame(0, OperationHistory::query()->where('action', 'visit.booking.cancel')->count());
    }

    public function test_time_conflict_and_stale_version_are_returned_without_partial_writes(): void
    {
        [$coordinator, $workOrder, $technician] = $this->context();
        $first = $this->draft($coordinator, $workOrder);
        $second = $this->draft($coordinator, $workOrder);
        $this->actingAs($coordinator)->post(route('work-orders.visits.confirm', [$workOrder, $first]), $this->confirmPayload($technician))->assertRedirect();
        $this->postJson(route('work-orders.visits.confirm', [$workOrder, $second]), $this->confirmPayload($technician))
            ->assertStatus(409)->assertJsonValidationErrors(['technician_ids'])->assertJsonPath('errors.technician_ids.0', 'BOOKING_TIME_CONFLICT');
        $this->postJson(route('work-orders.visits.confirm', [$workOrder, $first]), $this->confirmPayload($technician))
            ->assertStatus(409)->assertJsonValidationErrors(['expected_visit_version'])->assertJsonPath('errors.expected_visit_version.0', 'VISIT_VERSION_CONFLICT');
        $this->assertDatabaseCount('visit_bookings', 1);
        $this->assertSame('draft', $second->fresh()->status);
    }

    /** @return array{User, WorkOrder, User} */
    private function context(?User $coordinator = null): array
    {
        $coordinator ??= User::factory()->create(['role' => 'koordinator']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $site = Customer::query()->create(['name' => 'PT Visit HTTP Testing'])->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
        $workOrder = app(CreateWorkOrderService::class)->create($coordinator, [
            'command_key' => (string) Str::uuid(), 'origin_kind' => 'manual', 'manual_reason' => 'Permintaan pengujian',
            'initial_scope' => ['description' => 'Pulihkan kamera', 'acceptance_criteria' => 'Live feed tampil'],
            'customer_site_id' => $site->id, 'title' => 'Pemeriksaan kamera', 'description' => 'Kamera offline',
            'service_type' => 'maintenance', 'category' => 'cctv', 'priority' => 'medium', 'asset_ids' => [],
        ]);
        $workOrder->forceFill(['status' => 'assigned'])->save();
        $workOrder->technicians()->attach($technician, ['assigned_at' => now()]);

        return [$coordinator, $workOrder, $technician];
    }

    private function draft(User $coordinator, WorkOrder $workOrder): WorkVisit
    {
        return app(VisitSchedulingService::class)->createDraft($coordinator, $workOrder->id, $this->draftPayload());
    }

    private function draftPayload(): array
    {
        return ['command_key' => (string) Str::uuid(), 'starts_at' => '2026-11-12T09:00+07:00', 'ends_at' => '2026-11-12T11:00+07:00', 'zone_name' => 'Asia/Jakarta', 'reason' => 'Pemeriksaan kamera'];
    }

    private function confirmPayload(User $technician): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$technician->id]];
    }
}
