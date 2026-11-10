<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DashboardSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_dashboard_has_real_zero_counts_and_six_empty_months(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'teknisi']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('metrics', ['active' => 0, 'today' => 0, 'customers' => 0, 'pendingInvoices' => 0])
            ->has('trend', 6)->has('statuses', 0)->has('recentWorkOrders', 0)->etc());
    }

    #[DataProvider('visibilityRoles')]
    public function test_dashboard_and_listing_share_work_order_visibility(string $role, int $visibleCount): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->create(['role' => 'koordinator']);
        $otherTechnician = User::factory()->create(['role' => 'teknisi']);
        $owned = $this->workOrder($user, ['scheduled_at' => today()]);
        $assigned = $this->workOrder($other, ['scheduled_at' => today(), 'status' => 'assigned']);
        $assigned->technicians()->attach($user);
        $unrelated = $this->workOrder($other, ['scheduled_at' => today(), 'status' => 'in_progress']);
        $unrelated->technicians()->attach($otherTechnician);
        $visibleIds = $role === 'teknisi' ? [$assigned->id] : [$unrelated->id, $assigned->id, $owned->id];
        $statuses = $role === 'teknisi'
            ? [['status' => 'assigned', 'total' => 1]]
            : [['status' => 'assigned', 'total' => 1], ['status' => 'in_progress', 'total' => 1], ['status' => 'pending', 'total' => 1]];

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('dashboard')
            ->where('metrics.active', $visibleCount)->where('metrics.today', $visibleCount)->where('metrics.customers', $visibleCount)
            ->where('statuses', $statuses)
            ->where('trend', [
                ['month' => '2026-06', 'total' => 0], ['month' => '2026-07', 'total' => 0],
                ['month' => '2026-08', 'total' => 0], ['month' => '2026-09', 'total' => 0],
                ['month' => '2026-10', 'total' => 0], ['month' => '2026-11', 'total' => $visibleCount],
            ])
            ->has('recentWorkOrders', $visibleCount)->etc());
        $ids = array_column($response->inertiaProps('recentWorkOrders'), 'id');
        $this->assertSame($visibleIds, $ids);
        $this->assertSame([$user->name], collect($response->inertiaProps('recentWorkOrders'))->firstWhere('id', $assigned->id)['technicians']);

        $listing = $this->get(route('work-orders.index'))->assertOk();
        $listing->assertInertia(fn (Assert $page) => $page->component('work-orders/index')
            ->where('workOrders.total', $visibleCount)->where('workOrders.per_page', 10)->etc());
        $this->assertSame($visibleIds, array_column($listing->inertiaProps('workOrders.data'), 'id'));
    }

    /** @return array<string, array{string, int}> */
    public static function visibilityRoles(): array
    {
        return ['admin' => ['admin', 3], 'coordinator' => ['koordinator', 3], 'technician' => ['teknisi', 1]];
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirectToRoute('login');
    }

    #[DataProvider('invalidRoles')]
    public function test_dashboard_rejects_unsupported_roles(?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->workOrder($user);

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
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
    public function test_dashboard_logs_out_inactive_pending_and_rejected_accounts(string $role, bool $active, string $approval): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => $active, 'approval_status' => $approval]);
        $this->withSession(['dashboard-session' => 'must be cleared']);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirectToRoute('login')
            ->assertSessionMissing('dashboard-session')
            ->assertSessionHasErrors(['email' => 'Akun tidak aktif atau belum disetujui administrator.']);

        $this->assertGuest();
    }

    /** @return array<string, array{string, bool, string}> */
    public static function restrictedAccounts(): array
    {
        $accounts = [];

        foreach (User::ROLES as $role) {
            $accounts[$role.' inactive'] = [$role, false, 'approved'];
            $accounts[$role.' pending'] = [$role, true, 'pending'];
            $accounts[$role.' rejected'] = [$role, true, 'rejected'];
        }

        return $accounts;
    }

    public function test_revoked_membership_removes_work_from_dashboard_and_listing(): void
    {
        $user = User::factory()->create(['role' => 'teknisi']);
        $workOrder = $this->workOrder($user, ['scheduled_at' => today()]);
        $workOrder->technicians()->attach($user);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('metrics.active', 1)->etc());

        $workOrder->technicians()->detach($user);

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.active', 0)->where('metrics.today', 0)->where('metrics.customers', 0)
            ->where('statuses', [])->where('recentWorkOrders', [])
            ->where('trend', fn ($trend) => collect($trend)->sum('total') === 0)->etc());
        $this->get(route('work-orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workOrders.total', 0)->where('workOrders.data', [])->etc());
    }

    public function test_visibility_preserves_each_endpoints_date_status_and_order_filters(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $user = User::factory()->create(['role' => 'teknisi']);
        $creator = User::factory()->create(['role' => 'koordinator']);
        $current = $this->workOrder($creator, ['scheduled_at' => today()]);
        $olderClosed = $this->workOrder($creator, ['created_at' => '2026-04-01 12:00:00', 'scheduled_at' => today(), 'status' => 'closed']);
        $future = $this->workOrder($creator, ['created_at' => '2026-12-01 12:00:00', 'scheduled_at' => today()->addDay(), 'status' => 'assigned']);
        foreach ([$current, $olderClosed, $future] as $workOrder) {
            $workOrder->technicians()->attach($user);
        }
        $this->workOrder($user, ['scheduled_at' => today()]);
        $this->workOrder($creator, ['scheduled_at' => today()]);

        $dashboard = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $dashboard->assertInertia(fn (Assert $page) => $page
            ->where('metrics.active', 2)->where('metrics.today', 2)->where('metrics.customers', 3)
            ->where('statuses', [['status' => 'assigned', 'total' => 1], ['status' => 'closed', 'total' => 1], ['status' => 'pending', 'total' => 1]])
            ->where('trend', [
                ['month' => '2026-06', 'total' => 0], ['month' => '2026-07', 'total' => 0],
                ['month' => '2026-08', 'total' => 0], ['month' => '2026-09', 'total' => 0],
                ['month' => '2026-10', 'total' => 0], ['month' => '2026-11', 'total' => 1],
            ])->etc());
        $this->assertSame([$future->id, $current->id, $olderClosed->id], array_column($dashboard->inertiaProps('recentWorkOrders'), 'id'));
        $listing = $this->get(route('work-orders.index'))->assertOk();
        $this->assertSame([$future->id, $olderClosed->id, $current->id], array_column($listing->inertiaProps('workOrders.data'), 'id'));
    }

    #[DataProvider('revokedAccess')]
    public function test_summary_rechecks_access_when_called_directly_with_a_stale_actor(array $attributes): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'approval_status' => 'approved']);
        $user->fresh()->forceFill($attributes)->save();

        $this->expectException(AuthorizationException::class);

        app(DashboardSummary::class)->forUser($user);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function revokedAccess(): array
    {
        return [
            'role' => [['role' => 'invalid']], 'inactive' => [['is_active' => false]],
            'pending' => [['approval_status' => 'pending']], 'rejected' => [['approval_status' => 'rejected']],
        ];
    }

    public function test_monthly_history_preserves_decreases_and_empty_months(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->startOfDay());
        $user = User::factory()->create(['is_active' => true, 'role' => 'admin']);
        $this->workOrder($user, ['created_at' => '2026-05-01 12:00:00']);
        $this->workOrder($user, ['created_at' => '2026-05-02 12:00:00']);
        $this->workOrder($user, ['created_at' => '2026-06-01 12:00:00']);
        $this->workOrder($user, ['created_at' => '2026-09-01 12:00:00']);
        $this->workOrder($user, ['created_at' => '2026-04-01 12:00:00']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('trend', [
                ['month' => '2026-05', 'total' => 2], ['month' => '2026-06', 'total' => 1],
                ['month' => '2026-07', 'total' => 0], ['month' => '2026-08', 'total' => 0],
                ['month' => '2026-09', 'total' => 1], ['month' => '2026-10', 'total' => 0],
            ])->where('statuses', [['status' => 'pending', 'total' => 5]])->etc());
    }

    public function test_pending_invoices_count_only_own_issued_invoices_with_a_remaining_balance(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'admin']);
        $other = User::factory()->create();
        $workOrder = $this->workOrder($user);
        foreach ([['unpaid', 'issued', $user, '0.00'], ['partial', 'issued', $user, '40.00'], ['paid', 'issued', $user, '100.00'], ['draft', 'draft', $user, '0.00'], ['other', 'issued', $other, '0.00']] as [$number, $status, $creator, $paid]) {
            $invoice = Invoice::create(['invoice_number' => 'INV-'.$number, 'work_order_id' => $workOrder->id, 'customer_id' => $workOrder->site->customer_id, 'created_by' => $creator->id, 'bill_to_name' => 'Test customer']);
            $invoice->forceFill(['status' => $status, 'grand_total' => '100.00'])->save();
            if ($paid !== '0.00') {
                $invoice->payments()->create(['recorded_by' => $user->id, 'amount' => $paid, 'paid_at' => now(), 'payment_method' => 'transfer']);
            }
        }

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('metrics.pendingInvoices', 2)->etc());
    }

    public function test_recent_work_orders_are_bounded_and_relations_do_not_generate_n_plus_one_queries(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'teknisi']);
        for ($index = 0; $index < 12; $index++) {
            $this->workOrder($user)->technicians()->attach($user);
        }
        $this->actingAs($user);
        DB::enableQueryLog();
        try {
            $response = $this->get(route('dashboard'))->assertOk();
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(8, $response->inertiaProps('recentWorkOrders'));
        $this->assertLessThanOrEqual(18, $queryCount);
    }

    /** @param array<string, mixed> $attributes */
    private function workOrder(User $creator, array $attributes = []): WorkOrder
    {
        $customer = Customer::create(['name' => 'Test Customer']);
        $site = CustomerSite::create(['customer_id' => $customer->id, 'site_name' => 'Test Site', 'address' => 'Test Address']);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-'.fake()->uuid(), 'customer_site_id' => $site->id,
            'created_by' => $creator->id, 'title' => 'Test maintenance', 'description' => 'Test description',
            'service_type' => 'maintenance', 'category' => 'cctv',
        ]);
        $workOrder->forceFill($attributes)->save();

        return $workOrder;
    }
}
