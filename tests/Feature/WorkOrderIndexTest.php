<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkOrderController;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkOrderIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('work-orders.index'))->assertRedirectToRoute('login');
    }

    #[DataProvider('unrestrictedRoles')]
    public function test_admin_and_coordinator_see_all_work_orders_with_only_listing_fields(string $role): void
    {
        $viewer = User::factory()->create(['role' => $role]);
        $creator = User::factory()->create(['role' => 'koordinator']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $first = $this->workOrder($creator);
        $scheduled = $this->workOrder($creator, ['scheduled_at' => '2026-11-15 09:30:00']);
        $scheduled->technicians()->attach($technician);

        $this->actingAs($viewer)->get('/work-orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('work-orders/index')
                ->where('workOrders.total', 2)
                ->where('workOrders.data', [
                    [
                        'id' => $scheduled->id, 'number' => $scheduled->wo_number,
                        'title' => 'Test maintenance', 'customer' => 'Test Customer', 'site' => 'Test Site',
                        'service_type' => 'maintenance', 'priority' => 'medium', 'status' => 'pending',
                        'scheduled_at' => '2026-11-15', 'technicians' => [$technician->name],
                    ],
                    [
                        'id' => $first->id, 'number' => $first->wo_number,
                        'title' => 'Test maintenance', 'customer' => 'Test Customer', 'site' => 'Test Site',
                        'service_type' => 'maintenance', 'priority' => 'medium', 'status' => 'pending',
                        'scheduled_at' => null, 'technicians' => [],
                    ],
                ])
                ->where('auth.user', ['id' => $viewer->id, 'name' => $viewer->name, 'role' => $role])
                ->missing('customers')->missing('users')->missing('assets')->etc());
    }

    /** @return array<string, array{string}> */
    public static function unrestrictedRoles(): array
    {
        return ['admin' => ['admin'], 'coordinator' => ['koordinator']];
    }

    public function test_technician_sees_shared_assignments_but_not_unassigned_other_or_creator_only_work(): void
    {
        $viewer = User::factory()->create(['role' => 'teknisi']);
        $other = User::factory()->create(['role' => 'teknisi']);
        $creator = User::factory()->create(['role' => 'koordinator']);
        $assigned = $this->workOrder($creator);
        $assigned->technicians()->attach([$viewer->id, $other->id]);
        $this->workOrder($creator)->technicians()->attach($other);
        $this->workOrder($creator);
        $this->workOrder($viewer);

        $response = $this->actingAs($viewer)->get(route('work-orders.index', [
            'user_id' => $other->id, 'role' => 'admin', 'per_page' => 1000,
        ]))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('work-orders/index')
            ->where('workOrders.total', 1)->where('workOrders.per_page', 10)
            ->has('workOrders.data', 1)->where('workOrders.data.0.id', $assigned->id)->etc());
        $this->assertEqualsCanonicalizing([$viewer->name, $other->name], $response->inertiaProps('workOrders.data.0.technicians'));
    }

    #[DataProvider('invalidRoles')]
    public function test_invalid_or_missing_role_is_forbidden_and_has_no_work_order_navigation_link(?string $role): void
    {
        $viewer = User::factory()->create(['role' => $role]);

        $this->actingAs($viewer)->get(route('work-orders.index'))->assertForbidden();

        $response = $this->get(route('migration.test'))->assertOk();
        $item = collect($response->inertiaProps('navigation'))->firstWhere('id', 'work-orders');
        $this->assertNull($item['href']);
        $this->assertFalse($item['active']);
        $this->assertFalse($item['inertia']);
    }

    /** @return array<string, array{?string}> */
    public static function invalidRoles(): array
    {
        return [
            'missing' => [null], 'empty' => [''], 'legacy finance' => ['finance'],
            'legacy manager' => ['manager'], 'wrong case' => ['Admin'], 'unknown' => ['invalid'],
        ];
    }

    #[DataProvider('restrictedAccounts')]
    public function test_inactive_pending_and_rejected_accounts_are_logged_out(string $role, bool $active, string $approval): void
    {
        $viewer = User::factory()->create(['role' => $role, 'is_active' => $active, 'approval_status' => $approval]);
        $this->withSession(['work-order-session' => 'must be cleared']);

        $this->actingAs($viewer)->get(route('work-orders.index'))
            ->assertRedirectToRoute('login')->assertSessionMissing('work-order-session')
            ->assertSessionHasErrors(['email' => 'Akun tidak aktif atau belum disetujui administrator.']);

        $this->assertGuest();
    }

    /** @return array<string, array{string, bool, string}> */
    public static function restrictedAccounts(): array
    {
        $accounts = [];

        foreach (['admin', 'koordinator', 'teknisi'] as $role) {
            $accounts[$role.' inactive'] = [$role, false, 'approved'];
            $accounts[$role.' pending'] = [$role, true, 'pending'];
            $accounts[$role.' rejected'] = [$role, true, 'rejected'];
        }

        return $accounts;
    }

    public function test_empty_database_returns_a_valid_empty_page(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);

        $this->actingAs($viewer)->get(route('work-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('work-orders/index')
                ->where('workOrders.data', [])->where('workOrders.total', 0)
                ->where('workOrders.current_page', 1)->where('workOrders.last_page', 1)
                ->where('workOrders.from', null)->where('workOrders.to', null)
                ->where('workOrders.prev_page_url', null)->where('workOrders.next_page_url', null)->etc());
    }

    public function test_pagination_lists_ten_newest_records_then_the_remaining_records(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $ids = [];

        for ($index = 0; $index < 12; $index++) {
            $ids[] = $this->workOrder($viewer)->id;
        }

        $first = $this->actingAs($viewer)->get(route('work-orders.index'))->assertOk();

        $first->assertInertia(fn (Assert $page) => $page->where('workOrders.total', 12)
            ->where('workOrders.current_page', 1)->where('workOrders.last_page', 2)
            ->where('workOrders.from', 1)->where('workOrders.to', 10)
            ->where('workOrders.prev_page_url', null)->has('workOrders.data', 10)->etc());
        $this->assertSame(array_reverse(array_slice($ids, 2)), array_column($first->inertiaProps('workOrders.data'), 'id'));
        $this->get($first->inertiaProps('workOrders.next_page_url'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workOrders.total', 12)
                ->where('workOrders.current_page', 2)->where('workOrders.from', 11)->where('workOrders.to', 12)
                ->where('workOrders.next_page_url', null)->has('workOrders.data', 2)
                ->where('workOrders.data.0.id', $ids[1])->where('workOrders.data.1.id', $ids[0])->etc());
    }

    public function test_technician_pagination_counts_and_returns_only_assignments_on_every_page(): void
    {
        $viewer = User::factory()->create(['role' => 'teknisi']);
        $other = User::factory()->create(['role' => 'teknisi']);
        $ids = [];

        for ($index = 0; $index < 12; $index++) {
            $assigned = $this->workOrder($other);
            $assigned->technicians()->attach($viewer);
            $ids[] = $assigned->id;
            $this->workOrder($other)->technicians()->attach($other);
            $this->workOrder($viewer);
        }

        $first = $this->actingAs($viewer)->get(route('work-orders.index'))->assertOk();

        $first->assertInertia(fn (Assert $page) => $page->where('workOrders.total', 12)
            ->where('workOrders.last_page', 2)->has('workOrders.data', 10)->etc());
        $this->assertSame(array_reverse(array_slice($ids, 2)), array_column($first->inertiaProps('workOrders.data'), 'id'));
        $second = $this->get($first->inertiaProps('workOrders.next_page_url'))->assertOk();
        $second->assertInertia(fn (Assert $page) => $page->where('workOrders.total', 12)
            ->where('workOrders.current_page', 2)->where('workOrders.next_page_url', null)->etc());
        $this->assertSame([$ids[1], $ids[0]], array_column($second->inertiaProps('workOrders.data'), 'id'));
        $this->get(route('work-orders.index', ['page' => 3]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workOrders.total', 12)->where('workOrders.data', [])->etc());
    }

    public function test_legacy_status_priority_and_service_type_remain_readable(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $this->workOrder($viewer, ['status' => 'legacy_status', 'priority' => 'legacy_priority', 'service_type' => 'legacy_service']);

        $this->actingAs($viewer)->get(route('work-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workOrders.data.0.status', 'legacy_status')
                ->where('workOrders.data.0.priority', 'legacy_priority')
                ->where('workOrders.data.0.service_type', 'legacy_service')->etc());
    }

    #[DataProvider('allowedRoles')]
    public function test_navigation_uses_the_named_work_order_route_and_is_active(string $role): void
    {
        $viewer = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($viewer)->get(route('work-orders.index'))->assertOk();

        $item = collect($response->inertiaProps('navigation'))->firstWhere('id', 'work-orders');
        $this->assertSame(route('work-orders.index'), $item['href']);
        $this->assertSame('/work-orders', parse_url($item['href'], PHP_URL_PATH));
        $this->assertTrue($item['active']);
        $this->assertTrue($item['inertia']);
    }

    /** @return array<string, array{string}> */
    public static function allowedRoles(): array
    {
        return [...self::unrestrictedRoles(), 'technician' => ['teknisi']];
    }

    public function test_navigation_remains_active_on_a_work_order_subroute(): void
    {
        Route::middleware(['web', 'auth', 'role:admin,koordinator,teknisi'])
            ->get('/work-orders/audit-preview', [WorkOrderController::class, 'index'])->name('work-orders.audit-preview');
        $viewer = User::factory()->create(['role' => 'koordinator']);

        $response = $this->actingAs($viewer)->get('/work-orders/audit-preview')->assertOk();

        $item = collect($response->inertiaProps('navigation'))->firstWhere('id', 'work-orders');
        $this->assertSame(route('work-orders.index'), $item['href']);
        $this->assertTrue($item['active']);
    }

    public function test_full_page_eager_loads_relationships_without_n_plus_one_queries(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);

        for ($index = 0; $index < 10; $index++) {
            $this->workOrder($viewer)->technicians()->attach($viewer);
        }

        $this->actingAs($viewer);
        DB::enableQueryLog();

        try {
            $response = $this->get(route('work-orders.index'))->assertOk();
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertInertia(fn (Assert $page) => $page->has('workOrders.data', 10)->etc());
        $this->assertLessThanOrEqual(8, $queryCount);
    }

    /** @param array<string, mixed> $attributes */
    private function workOrder(User $creator, array $attributes = []): WorkOrder
    {
        $customer = Customer::create(['name' => 'Test Customer']);
        $site = CustomerSite::create(['customer_id' => $customer->id, 'site_name' => 'Test Site', 'address' => 'Test Address']);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-'.fake()->uuid(), 'customer_site_id' => $site->id,
            'created_by' => $creator->id, 'title' => 'Test maintenance', 'description' => 'Private description',
            'service_type' => 'maintenance', 'category' => 'cctv',
        ]);
        $workOrder->forceFill($attributes)->save();

        return $workOrder;
    }
}
