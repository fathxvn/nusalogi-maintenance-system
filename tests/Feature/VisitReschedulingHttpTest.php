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

class VisitReschedulingHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_can_replace_and_replay_a_superseded_source_through_http(): void
    {
        [$actor, $wo, $tech] = $this->context();
        $source = $this->draft($actor, $wo);
        app(VisitSchedulingService::class)->confirm($actor, $source->id, $this->confirmPayload($tech));
        $url = route('work-orders.visits.reschedule', [$wo, $source]);
        $this->actingAs($actor)->get(route('work-orders.visits.index', $wo))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('visits.data.0.reschedule_url', $url)
                ->where('visits.data.0.active_technician_ids', [$tech->id])
                ->where('visits.data.0.reschedule_command_key', fn ($key) => Str::isUuid($key))->etc());
        $payload = $this->reschedulePayload($tech);
        $this->post($url, [...$payload, 'status' => 'draft', 'previous_visit_id' => 999])->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.visits.index', $wo);
        $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.visits.index', $wo);
        $successor = $source->successorVisit()->sole();
        $this->assertSame('superseded', $source->fresh()->status);
        $this->assertSame('confirmed', $successor->status);
        $this->assertSame($source->id, $successor->previous_visit_id);
        $this->assertSame('cancelled', $source->bookings()->sole()->status);
        $this->assertSame([$tech->id], $successor->bookings()->pluck('technician_id')->all());
        $this->assertDatabaseCount('work_visits', 2);
        $this->assertDatabaseCount('visit_bookings', 2);
        $this->assertSame(1, OperationHistory::query()->where('action', 'visit.reschedule')->count());
        $this->assertSame('assigned', $wo->fresh()->status);
        $this->get(route('work-orders.visits.index', $wo))->assertInertia(fn (Assert $page) => $page
            ->where('visits.data.0.previous_visit_no', 1)->where('visits.data.1.successor_visit_no', 2)
            ->where('visits.data.1.reschedule_url', null)->etc());
        $this->postJson($url, [...$payload, 'reason' => 'Changed request'])->assertStatus(409)->assertJsonPath('errors.command_key.0', 'COMMAND_KEY_REUSED');
    }

    #[DataProvider('forbiddenActors')]
    public function test_noncoordinators_and_ineligible_accounts_cannot_reschedule(array $attributes, bool $logout): void
    {
        [$actor, $wo, $tech] = $this->context();
        $source = $this->draft($actor, $wo);
        app(VisitSchedulingService::class)->confirm($actor, $source->id, $this->confirmPayload($tech));
        $actor->forceFill($attributes)->save();
        if ($actor->hasRole('teknisi')) {
            $wo->technicians()->attach($actor, ['assigned_at' => now()]);
        }
        $response = $this->actingAs($actor)->post(route('work-orders.visits.reschedule', [$wo, $source]), $this->reschedulePayload($tech));
        if ($logout) {
            $response->assertRedirectToRoute('login');
        } else {
            $response->assertForbidden();
            $this->get(route('work-orders.visits.index', $wo))->assertInertia(fn (Assert $page) => $page
                ->where('visits.data.0.reschedule_url', null)->where('visits.data.0.reschedule_command_key', null)->etc());
        }
        $this->assertDatabaseCount('work_visits', 1);
        $this->assertSame('confirmed', $source->fresh()->status);
    }

    public static function forbiddenActors(): array
    {
        return ['admin' => [['role' => 'admin'], false], 'technician' => [['role' => 'teknisi'], false],
            'inactive' => [['is_active' => false], true], 'pending' => [['approval_status' => 'pending'], true], 'rejected' => [['approval_status' => 'rejected'], true]];
    }

    public function test_guest_and_cross_parent_requests_cannot_mutate_the_source(): void
    {
        [$actor, $wo, $tech] = $this->context();
        [, $other] = $this->context($actor);
        $source = $this->draft($actor, $wo);
        $this->post(route('work-orders.visits.reschedule', [$wo, $source]), [])->assertRedirectToRoute('login');
        $this->actingAs($actor)->post(route('work-orders.visits.reschedule', [$other, $source]), $this->reschedulePayload($tech))->assertNotFound();
        $this->assertSame('draft', $source->fresh()->status);
        $this->assertNull($source->successorVisit);
    }

    public function test_browser_validation_returns_input_errors_in_the_action_bag_and_preserves_reservation(): void
    {
        [$actor, $wo, $tech] = $this->context();
        $source = $this->draft($actor, $wo);
        app(VisitSchedulingService::class)->confirm($actor, $source->id, $this->confirmPayload($tech));
        $page = route('work-orders.visits.index', $wo);
        $payload = [...$this->reschedulePayload($tech), 'reason' => ''];
        $this->actingAs($actor)->from($page)->withHeader('X-Inertia-Error-Bag', 'reschedule-visit-'.$source->id)
            ->post(route('work-orders.visits.reschedule', [$wo, $source]), $payload)->assertRedirect($page)->assertSessionHasErrors(['reason']);
        $this->withCookie(config('session.cookie'), session()->getId())->get($page, ['X-Inertia-Error-Bag' => 'reschedule-visit-'.$source->id])->assertInertia(fn (Assert $page) => $page->has('errors.reschedule-visit-'.$source->id.'.reason')->etc());
        $this->assertSame('confirmed', $source->fresh()->status);
        $this->assertSame('confirmed', $source->bookings()->sole()->status);
        $this->assertDatabaseCount('work_visits', 1);
        $this->assertSame(0, OperationHistory::query()->where('action', 'visit.reschedule')->count());
    }

    public function test_started_sources_hide_reschedule_actions_and_http_state_conflict_preserves_data(): void
    {
        [$actor, $wo, $tech] = $this->context();
        $source = $this->draft($actor, $wo);
        app(VisitSchedulingService::class)->confirm($actor, $source->id, $this->confirmPayload($tech));
        $source->bookings()->sole()->update(['actual_start_at' => '2026-11-12 02:00:00']);
        $this->actingAs($actor)->get(route('work-orders.visits.index', $wo))->assertInertia(fn (Assert $page) => $page
            ->where('visits.data.0.reschedule_url', null)->where('visits.data.0.reschedule_command_key', null)->etc());
        $this->postJson(route('work-orders.visits.reschedule', [$wo, $source]), $this->reschedulePayload($tech))
            ->assertStatus(409)->assertJsonPath('errors.visit_id.0', 'VISIT_RESCHEDULE_NOT_ALLOWED');
        $this->assertDatabaseCount('work_visits', 1);
    }

    public function test_stale_version_and_conflict_responses_do_not_release_the_original_booking(): void
    {
        [$actor, $wo, $tech] = $this->context();
        $service = app(VisitSchedulingService::class);
        $source = $this->draft($actor, $wo);
        $service->confirm($actor, $source->id, $this->confirmPayload($tech));
        $url = route('work-orders.visits.reschedule', [$wo, $source]);
        $this->actingAs($actor)->postJson($url, [...$this->reschedulePayload($tech), 'expected_visit_version' => 0])
            ->assertStatus(409)->assertJsonPath('errors.expected_visit_version.0', 'VISIT_VERSION_CONFLICT');
        $other = $service->createDraft($actor, $wo->id, [
            ...$this->draftPayload(), 'starts_at' => '2026-11-12T12:00+07:00', 'ends_at' => '2026-11-12T14:00+07:00',
        ]);
        $service->confirm($actor, $other->id, $this->confirmPayload($tech));
        $this->postJson($url, [...$this->reschedulePayload($tech), 'ends_at' => '2026-11-12T13:00+07:00'])
            ->assertStatus(409)->assertJsonPath('errors.technician_ids.0', 'BOOKING_TIME_CONFLICT');
        $this->assertSame('confirmed', $source->fresh()->status);
        $this->assertSame(1, $source->fresh()->lock_version);
        $this->assertSame('confirmed', $source->bookings()->sole()->status);
        $this->assertSame(0, $source->successorVisit()->count());
        $this->assertDatabaseCount('work_visits', 2);
        $this->assertSame(0, OperationHistory::query()->where('action', 'visit.reschedule')->count());
    }

    private function reschedulePayload(User $technician): array
    {
        return [...$this->draftPayload(), 'expected_visit_version' => 1, 'technician_ids' => [$technician->id],
            'starts_at' => '2026-11-12T10:00+07:00', 'ends_at' => '2026-11-12T12:00+07:00', 'reason' => 'Customer requested a new time'];
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
