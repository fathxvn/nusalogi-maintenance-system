<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_dashboard_excludes_unrelated_work_orders_and_includes_assigned_work(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['is_active' => true, 'role' => 'admin']);
        $other = User::factory()->create();
        $owned = $this->workOrder($user, ['scheduled_at' => today()]);
        $assigned = $this->workOrder($other, ['scheduled_at' => today(), 'status' => 'assigned']);
        $assigned->technicians()->attach($user);
        $unrelated = $this->workOrder($other, ['scheduled_at' => today()]);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('dashboard')
            ->where('metrics.active', 2)->where('metrics.today', 2)->where('metrics.customers', 2)
            ->has('recentWorkOrders', 2)->etc());
        $ids = array_column($response->inertiaProps('recentWorkOrders'), 'id');
        $this->assertContains($owned->id, $ids);
        $this->assertContains($assigned->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
        $this->assertSame([$user->name], collect($response->inertiaProps('recentWorkOrders'))->firstWhere('id', $assigned->id)['technicians']);
    }

    public function test_monthly_history_preserves_decreases_and_empty_months(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->startOfDay());
        $user = User::factory()->create(['is_active' => true]);
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
        $user = User::factory()->create(['is_active' => true]);
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
        $user = User::factory()->create(['is_active' => true]);
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
