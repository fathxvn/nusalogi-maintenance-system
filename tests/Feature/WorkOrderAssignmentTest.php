<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_opens_assignment_page_with_only_eligible_technician_summaries(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($this->coordinator());
        $first = $this->technician(['name' => 'Alpha Technician']);
        $second = $this->technician(['name' => 'Beta Technician']);
        foreach (self::ineligibleTechnicians() as [$attributes]) {
            $this->technician($attributes);
        }

        $response = $this->actingAs($coordinator)->get(route('work-orders.assignment.edit', $workOrder))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('work-orders/assign')
            ->where('workOrder', ['id' => $workOrder->id, 'number' => $workOrder->wo_number, 'title' => 'Camera maintenance',
                'status' => 'pending', 'customer' => 'Test Customer', 'site' => 'Test Site'])
            ->where('technicians', [['id' => $first->id, 'name' => $first->name], ['id' => $second->id, 'name' => $second->name]])
            ->where('links', ['index' => route('work-orders.index'), 'update' => route('work-orders.assignment.update', $workOrder)])
            ->etc());
        $item = collect($response->inertiaProps('navigation'))->firstWhere('id', 'work-orders');
        $this->assertTrue($item['active']);
        $this->assertTrue($item['inertia']);
    }

    public function test_assignment_page_supports_no_eligible_technicians(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);

        $this->actingAs($coordinator)->get(route('work-orders.assignment.edit', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('technicians', [])->etc());
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return ['page' => ['GET', 'edit'], 'assignment' => ['PATCH', 'update']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_redirects_to_login(string $method, string $action): void
    {
        $workOrder = $this->workOrder($this->coordinator());

        $this->call($method, route('work-orders.assignment.'.$action, $workOrder))->assertRedirectToRoute('login');

        $this->assertUntouched($workOrder);
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function forbiddenEndpoints(): array
    {
        $cases = [];
        foreach (self::endpoints() as $name => [$method, $action]) {
            foreach (['admin', 'teknisi', 'finance', 'manager', null, '', 'Koordinator'] as $role) {
                $cases[$name.' '.($role ?? 'missing role')] = [$method, $action, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('forbiddenEndpoints')]
    public function test_other_roles_are_forbidden_even_when_they_created_the_work_order(string $method, string $action, ?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $workOrder = $this->workOrder($user);
        $technician = $this->technician();

        $this->actingAs($user)->call($method, route('work-orders.assignment.'.$action, $workOrder), ['technician_ids' => [$technician->id]])->assertForbidden();

        $this->assertUntouched($workOrder);
    }

    /** @return array<string, array{string, string, bool, string}> */
    public static function restrictedEndpoints(): array
    {
        $cases = [];
        foreach (self::endpoints() as $name => [$method, $action]) {
            foreach (['inactive' => [false, 'approved'], 'pending' => [true, 'pending'], 'rejected' => [true, 'rejected']] as $state => [$active, $approval]) {
                $cases[$name.' '.$state] = [$method, $action, $active, $approval];
            }
        }

        return $cases;
    }

    #[DataProvider('restrictedEndpoints')]
    public function test_inactive_and_unapproved_coordinators_are_logged_out(string $method, string $action, bool $active, string $approval): void
    {
        $coordinator = $this->coordinator(['is_active' => $active, 'approval_status' => $approval]);
        $workOrder = $this->workOrder($coordinator);
        $technician = $this->technician();

        $this->actingAs($coordinator)->call($method, route('work-orders.assignment.'.$action, $workOrder), ['technician_ids' => [$technician->id]])
            ->assertRedirectToRoute('login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertUntouched($workOrder);
    }

    /** @return array<string, array{int}> */
    public static function technicianCounts(): array
    {
        return ['one technician' => [1], 'multiple technicians' => [3]];
    }

    #[DataProvider('technicianCounts')]
    public function test_coordinator_assigns_technicians_with_timestamps_status_and_history(int $count): void
    {
        $this->travelTo('2026-11-10 10:30:00');
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($this->coordinator());
        $technicians = User::factory()->count($count)->create(['role' => 'teknisi']);

        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => $technicians->modelKeys()])
            ->assertRedirectToRoute('work-orders.index')->assertSessionHasNoErrors()->assertSessionHas('success', 'Teknisi berhasil ditugaskan.');

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'assigned', 'created_by' => $workOrder->created_by]);
        foreach ($technicians as $technician) {
            $this->assertDatabaseHas('work_order_technicians', [
                'work_order_id' => $workOrder->id, 'user_id' => $technician->id, 'assigned_at' => '2026-11-10 10:30:00',
                'created_at' => '2026-11-10 10:30:00', 'updated_at' => '2026-11-10 10:30:00',
            ]);
        }
        $this->assertDatabaseCount('work_order_technicians', $count);
        $this->assertDatabaseHas('work_order_updates', [
            'work_order_id' => $workOrder->id, 'changed_by' => $coordinator->id,
            'from_status' => 'pending', 'to_status' => 'assigned', 'notes' => 'Penugasan teknisi oleh Koordinator.',
            'created_at' => '2026-11-10 10:30:00',
        ]);
        $this->assertDatabaseCount('work_order_updates', 1);
        $response = $this->get(route('work-orders.index'))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Teknisi berhasil ditugaskan.')
            ->where('workOrders.data.0.status', 'assigned')->etc());
        $this->assertCount($count, $response->inertiaProps('workOrders.data.0.technicians'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'missing' => [[], 'technician_ids'], 'empty' => [['technician_ids' => []], 'technician_ids'],
            'null' => [['technician_ids' => null], 'technician_ids'], 'scalar' => [['technician_ids' => 1], 'technician_ids'],
            'associative' => [['technician_ids' => ['person' => 999999]], 'technician_ids'],
            'nonexistent' => [['technician_ids' => [999999]], 'technician_ids.0'],
            'invalid string' => [['technician_ids' => ['invalid']], 'technician_ids.0'],
            'nested array' => [['technician_ids' => [[1]]], 'technician_ids.0'],
            'null entry' => [['technician_ids' => [null]], 'technician_ids.0'],
            'fraction' => [['technician_ids' => [1.5]], 'technician_ids.0'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected_without_partial_records(array $payload, string $field): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);

        $this->actingAs($coordinator)->from(route('work-orders.assignment.edit', $workOrder))
            ->patch(route('work-orders.assignment.update', $workOrder), $payload)
            ->assertRedirectToRoute('work-orders.assignment.edit', $workOrder)->assertSessionHasErrors($field);

        $this->assertUntouched($workOrder);
    }

    public function test_duplicate_ids_are_rejected_including_mixed_integer_and_string_values(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $technician = $this->technician();

        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => [$technician->id, (string) $technician->id]])
            ->assertSessionHasErrors(['technician_ids.0', 'technician_ids.1']);

        $this->assertUntouched($workOrder);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function ineligibleTechnicians(): array
    {
        return [
            'admin' => [['role' => 'admin']], 'coordinator' => [['role' => 'koordinator']],
            'missing role' => [['role' => null]], 'legacy role' => [['role' => 'finance']],
            'inactive' => [['is_active' => false]], 'pending' => [['approval_status' => 'pending']],
            'rejected' => [['approval_status' => 'rejected']],
        ];
    }

    #[DataProvider('ineligibleTechnicians')]
    public function test_ineligible_technicians_cannot_be_assigned_even_alongside_an_eligible_one(array $attributes): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $eligible = $this->technician();
        $invalid = $this->technician($attributes);

        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => [$eligible->id, $invalid->id]])
            ->assertSessionHasErrors('technician_ids.1');

        $this->assertUntouched($workOrder);
    }

    /** @return array<string, array{string, string, string}> */
    public static function nonPendingEndpoints(): array
    {
        $cases = [];
        foreach (self::endpoints() as $name => [$method, $action]) {
            foreach (['assigned', 'in_progress', 'on_hold', 'pending_review', 'rework', 'closed', 'cancelled', 'legacy_status'] as $status) {
                $cases[$name.' '.$status] = [$method, $action, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('nonPendingEndpoints')]
    public function test_non_pending_work_orders_cannot_be_initially_assigned(string $method, string $action, string $status): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator, $status);
        $existing = $this->technician();
        $replacement = $this->technician();
        $workOrder->technicians()->attach($existing, ['assigned_at' => '2026-11-01 10:00:00']);

        $response = $this->actingAs($coordinator)->call($method, route('work-orders.assignment.'.$action, $workOrder), ['technician_ids' => [$replacement->id]]);

        if ($method === 'GET') {
            $response->assertNotFound();
        } else {
            $response->assertSessionHasErrors('technician_ids');
        }
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => $status]);
        $this->assertDatabaseHas('work_order_technicians', ['work_order_id' => $workOrder->id, 'user_id' => $existing->id, 'assigned_at' => '2026-11-01 10:00:00']);
        $this->assertDatabaseCount('work_order_technicians', 1);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_repeated_submission_does_not_duplicate_or_change_assignments_and_history(): void
    {
        $this->travelTo('2026-11-10 10:30:00');
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $technician = $this->technician();
        $payload = ['technician_ids' => [$technician->id]];
        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), $payload)->assertSessionHasNoErrors();
        $this->travel(1)->hour();

        $this->patch(route('work-orders.assignment.update', $workOrder), $payload)->assertSessionHasErrors('technician_ids');

        $this->assertDatabaseCount('work_order_technicians', 1);
        $this->assertDatabaseCount('work_order_updates', 1);
        $this->assertDatabaseHas('work_order_technicians', ['work_order_id' => $workOrder->id, 'user_id' => $technician->id, 'assigned_at' => '2026-11-10 10:30:00']);
    }

    public function test_another_work_orders_assignments_and_history_are_unchanged_and_spoofed_fields_are_ignored(): void
    {
        $this->travelTo('2026-11-10 10:30:00');
        $coordinator = $this->coordinator();
        $first = $this->workOrder($coordinator);
        $second = $this->workOrder($coordinator);
        $firstTechnician = $this->technician();
        $secondTechnician = $this->technician();
        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $first), ['technician_ids' => [$firstTechnician->id]])->assertSessionHasNoErrors();
        $originalHistory = $first->updates()->sole()->toArray();

        $this->patch(route('work-orders.assignment.update', $second), [
            'technician_ids' => [$secondTechnician->id], 'work_order_id' => $first->id, 'status' => 'closed',
            'changed_by' => $firstTechnician->id, 'assigned_at' => '2000-01-01', 'notes' => 'Forged history',
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->assertSame($originalHistory, $first->updates()->sole()->toArray());
        $this->assertSame([$firstTechnician->id], $first->technicians()->pluck('users.id')->all());
        $this->assertSame([$secondTechnician->id], $second->technicians()->pluck('users.id')->all());
        $this->assertDatabaseHas('work_order_updates', ['work_order_id' => $second->id, 'changed_by' => $coordinator->id, 'to_status' => 'assigned', 'notes' => 'Penugasan teknisi oleh Koordinator.']);
        $this->assertDatabaseHas('work_order_technicians', ['work_order_id' => $second->id, 'assigned_at' => '2026-11-10 10:30:00']);
        $this->assertDatabaseCount('work_order_updates', 2);
    }

    /** @return array<string, array{string}> */
    public static function failingWrites(): array
    {
        return ['pivot insertion' => ['work_order_technicians'], 'history insertion' => ['work_order_updates']];
    }

    #[DataProvider('failingWrites')]
    public function test_write_failure_rolls_back_status_pivots_and_history_and_restores_previous_pivots(string $table): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $existing = $this->technician();
        $replacement = $this->technician();
        $workOrder->technicians()->attach($existing, ['assigned_at' => '2026-11-01 10:00:00']);
        $reachedWrite = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($table, &$reachedWrite): void {
            if (str_starts_with($sql, 'insert into `'.$table.'`')) {
                $reachedWrite = true;
                throw new RuntimeException('Simulated assignment write failure.');
            }
        });
        Exceptions::fake();

        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => [$replacement->id]])->assertInternalServerError();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertTrue($reachedWrite);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'pending']);
        $this->assertDatabaseHas('work_order_technicians', ['work_order_id' => $workOrder->id, 'user_id' => $existing->id, 'assigned_at' => '2026-11-01 10:00:00']);
        $this->assertDatabaseCount('work_order_technicians', 1);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_assigned_work_order_appears_only_in_the_selected_technicians_listing(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $technician = $this->technician();
        $other = $this->technician();
        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => [$technician->id]])->assertSessionHasNoErrors();

        $this->actingAs($technician)->get(route('work-orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workOrders.total', 1)->where('workOrders.data.0.id', $workOrder->id)->etc());
        $this->actingAs($other)->get(route('work-orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workOrders.total', 0)->where('workOrders.data', [])->etc());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unauthorizedActors(): array
    {
        return [
            'admin' => [['role' => 'admin']], 'technician' => [['role' => 'teknisi']],
            'no role' => [['role' => null]], 'legacy role' => [['role' => 'manager']],
            'inactive coordinator' => [['is_active' => false]],
            'pending coordinator' => [['approval_status' => 'pending']], 'rejected coordinator' => [['approval_status' => 'rejected']],
        ];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_status_service_rejects_unauthorized_actors_even_when_they_are_the_creator(array $attributes): void
    {
        $actor = $this->coordinator($attributes);
        $workOrder = $this->workOrder($actor);
        $workOrder->technicians()->attach($this->technician());

        try {
            app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $actor, 'assigned');
            $this->fail('An unauthorized actor was allowed to assign technicians.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Hanya Koordinator yang boleh melakukan penugasan.', $exception->getMessage());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'pending']);
        $this->assertDatabaseCount('work_order_updates', 0);
        $this->assertDatabaseCount('work_order_technicians', 1);
    }

    #[DataProvider('ineligibleTechnicians')]
    public function test_status_service_rejects_ineligible_attached_technicians(array $attributes): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $workOrder->technicians()->attach([$this->technician()->id, $this->technician($attributes)->id]);

        try {
            app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $coordinator, 'assigned');
            $this->fail('An ineligible technician was accepted by the status service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('technicians', $exception->errors());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'pending']);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_status_service_does_not_trust_stale_actor_role_or_activity(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $workOrder->technicians()->attach($this->technician());
        DB::table('users')->where('id', $coordinator->id)->update(['role' => 'admin', 'is_active' => false]);

        try {
            app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $coordinator, 'assigned');
            $this->fail('The stale actor model bypassed authorization.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Hanya Koordinator yang boleh melakukan penugasan.', $exception->getMessage());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'pending']);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_status_service_allows_a_non_creator_coordinator_and_preserves_existing_start_transition(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($this->coordinator());
        $technician = $this->technician();
        $workOrder->technicians()->attach($technician);

        app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $coordinator, 'assigned');
        app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $technician, 'in_progress');

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'in_progress']);
        $this->assertDatabaseHas('work_order_updates', ['changed_by' => $coordinator->id, 'from_status' => 'pending', 'to_status' => 'assigned']);
        $this->assertDatabaseHas('work_order_updates', ['changed_by' => $technician->id, 'from_status' => 'assigned', 'to_status' => 'in_progress']);
        $this->assertDatabaseCount('work_order_updates', 2);
    }

    public function test_status_service_requires_an_attached_technician(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);

        try {
            app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $coordinator, 'assigned');
            $this->fail('Assignment without technicians was allowed.');
        } catch (ValidationException $exception) {
            $this->assertSame(['technicians' => ['WO belum memiliki teknisi.']], $exception->errors());
        }

        $this->assertUntouched($workOrder);
    }

    public function test_technician_eligibility_is_rechecked_after_request_validation(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $technician = $this->technician();
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($technician, &$changed): void {
            if (! $changed && str_contains($sql, 'from `users`') && str_contains($sql, 'for update') && str_contains($sql, ' in (')) {
                $changed = true;
                DB::table('users')->where('id', $technician->id)->update(['is_active' => false]);
            }
        });

        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => [$technician->id]])
            ->assertSessionHasErrors('technician_ids');

        $this->assertTrue($changed);
        $this->assertUntouched($workOrder);
    }

    #[DataProvider('ineligibleTechnicians')]
    public function test_existing_start_transition_rejects_ineligible_assignees_on_direct_service_calls(array $attributes): void
    {
        $workOrder = $this->workOrder($this->coordinator(), 'assigned');
        $actor = $this->technician($attributes);
        $workOrder->technicians()->attach($actor);

        try {
            app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $actor, 'in_progress');
            $this->fail('An ineligible assignee bypassed the status service authorization.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Pengguna bukan teknisi yang ditugaskan.', $exception->getMessage());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'assigned']);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_existing_start_transition_still_rejects_an_unassigned_technician(): void
    {
        $workOrder = $this->workOrder($this->coordinator(), 'assigned');
        $actor = $this->technician();
        $workOrder->technicians()->attach($this->technician());

        try {
            app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $actor, 'in_progress');
            $this->fail('An unassigned technician started the work order.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Pengguna bukan teknisi yang ditugaskan.', $exception->getMessage());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'assigned']);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function changedCoordinatorAttributes(): array
    {
        return ['role revoked' => [['role' => 'admin']], 'deactivated' => [['is_active' => false]], 'approval revoked' => [['approval_status' => 'rejected']]];
    }

    #[DataProvider('changedCoordinatorAttributes')]
    public function test_coordinator_authorization_is_rechecked_inside_the_assignment_transaction(array $attributes): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $technician = $this->technician();
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($coordinator, $attributes, &$changed): void {
            if (! $changed && str_contains($sql, 'from `users`') && str_contains($sql, 'for update') && str_contains($sql, 'limit 1')) {
                $changed = true;
                DB::table('users')->where('id', $coordinator->id)->update($attributes);
            }
        });

        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => [$technician->id]])->assertForbidden();

        $this->assertTrue($changed);
        $this->assertUntouched($workOrder);
    }

    public function test_status_is_rechecked_after_route_binding_and_request_validation(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $technician = $this->technician();
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($workOrder, &$changed): void {
            if (! $changed && str_contains($sql, 'from `work_orders`') && str_contains($sql, 'for update')) {
                $changed = true;
                DB::table('work_orders')->where('id', $workOrder->id)->update(['status' => 'assigned']);
            }
        });

        $this->actingAs($coordinator)->patch(route('work-orders.assignment.update', $workOrder), ['technician_ids' => [$technician->id]])->assertSessionHasErrors('technician_ids');

        $this->assertTrue($changed);
        $this->assertUntouched($workOrder);
    }

    public function test_status_service_repeated_assignment_does_not_create_duplicate_history(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        $workOrder->technicians()->attach($this->technician());
        app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $coordinator, 'assigned');

        try {
            app(WorkOrderStatusService::class)->changeStatus($workOrder->id, $coordinator, 'assigned');
            $this->fail('A repeated assignment transition was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'assigned']);
        $this->assertDatabaseCount('work_order_updates', 1);
        $this->assertDatabaseCount('work_order_technicians', 1);
    }

    public function test_assignment_links_include_only_pending_rows_on_each_paginated_page(): void
    {
        $coordinator = $this->coordinator();
        $ids = [];
        for ($index = 0; $index < 12; $index++) {
            $ids[] = $this->workOrder($coordinator)->id;
        }

        $first = $this->actingAs($coordinator)->get(route('work-orders.index'))->assertOk();

        $firstLinks = $first->inertiaProps('assignmentLinks');
        $this->assertSame(array_reverse(array_slice($ids, 2)), array_keys($firstLinks));
        $second = $this->get($first->inertiaProps('workOrders.next_page_url'))->assertOk();
        $this->assertSame([$ids[1], $ids[0]], array_keys($second->inertiaProps('assignmentLinks')));
    }

    public function test_technician_options_and_work_order_relations_do_not_generate_n_plus_one_queries(): void
    {
        $coordinator = $this->coordinator();
        $workOrder = $this->workOrder($coordinator);
        User::factory()->count(12)->create(['role' => 'teknisi']);
        $this->actingAs($coordinator);
        DB::enableQueryLog();

        try {
            $response = $this->get(route('work-orders.assignment.edit', $workOrder))->assertOk();
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertInertia(fn (Assert $page) => $page->has('technicians', 12)->etc());
        $this->assertLessThanOrEqual(8, $queryCount);
    }

    #[DataProvider('listingRoles')]
    public function test_only_coordinators_receive_assignment_urls_for_pending_records_on_the_current_page(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $pending = $this->workOrder($user);
        $assigned = $this->workOrder($user, 'assigned');
        $pending->technicians()->attach($user);
        $assigned->technicians()->attach($user);

        $response = $this->actingAs($user)->get(route('work-orders.index'))->assertOk();

        $links = $response->inertiaProps('assignmentLinks');
        if ($role === 'koordinator') {
            $this->assertSame([$pending->id => route('work-orders.assignment.edit', $pending)], $links);
        } else {
            $this->assertSame([], $links);
        }
    }

    /** @return array<string, array{string}> */
    public static function listingRoles(): array
    {
        return ['admin' => ['admin'], 'coordinator' => ['koordinator'], 'technician' => ['teknisi']];
    }

    #[DataProvider('endpoints')]
    public function test_missing_work_order_returns_not_found(string $method, string $action): void
    {
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->call($method, route('work-orders.assignment.'.$action, 999999))->assertNotFound();

        $this->assertDatabaseCount('work_order_technicians', 0);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    private function assertUntouched(WorkOrder $workOrder): void
    {
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'pending']);
        $this->assertDatabaseCount('work_order_technicians', 0);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    /** @param array<string, mixed> $attributes */
    private function coordinator(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'koordinator', ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function technician(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'teknisi', ...$attributes]);
    }

    private function workOrder(User $creator, string $status = 'pending'): WorkOrder
    {
        $site = Customer::create(['name' => 'Test Customer'])->sites()->create(['site_name' => 'Test Site', 'address' => 'Jakarta']);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-'.fake()->uuid(), 'customer_site_id' => $site->id, 'created_by' => $creator->id,
            'title' => 'Camera maintenance', 'description' => 'Camera offline', 'service_type' => 'maintenance', 'category' => 'cctv',
        ]);
        $workOrder->forceFill(['status' => $status])->save();

        return $workOrder;
    }
}
