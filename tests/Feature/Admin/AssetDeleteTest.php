<?php

namespace Tests\Feature\Admin;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_and_detail_provide_asset_specific_destroy_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createAsset();

        $this->actingAs($admin)->get(route('admin.assets.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('assets.data.0.links.destroy', route('admin.assets.destroy', $asset))->etc());
        $this->get(route('admin.assets.show', $asset))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.destroy', route('admin.assets.destroy', $asset))->etc());
    }

    public function test_admin_deletes_unrelated_asset_and_returns_to_index_with_success_feedback(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createAsset();
        $site = $asset->site;
        $customer = $site->customer;
        $other = $site->assets()->create(['asset_code' => 'OTHER', 'category' => 'networking']);

        $this->actingAs($admin)->delete(route('admin.assets.destroy', $asset))
            ->assertRedirectToRoute('admin.assets.index')->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Asset berhasil dihapus.');

        $this->assertModelMissing($asset);
        foreach ([$site, $customer, $other] as $record) {
            $this->assertModelExists($record);
        }
        $this->get(route('admin.assets.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Asset berhasil dihapus.')->has('assets.data', 1)->where('assets.data.0.id', $other->id)->etc());
    }

    public function test_delete_uses_route_asset_instead_of_payload_identity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createAsset();
        $other = $asset->site->assets()->create(['asset_code' => 'OTHER', 'category' => 'cctv']);

        $this->actingAs($admin)->delete(route('admin.assets.destroy', $asset), ['id' => $other->id, 'asset_id' => $other->id])
            ->assertRedirectToRoute('admin.assets.index');

        $this->assertModelMissing($asset);
        $this->assertModelExists($other);
    }

    #[DataProvider('workOrderStatuses')]
    public function test_work_order_asset_cannot_be_deleted_and_all_operational_history_is_preserved(string $status): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createAsset();
        $workOrder = $this->createWorkOrder($asset, $admin);
        $workOrder->forceFill(['status' => $status])->save();
        $workOrder->assets()->attach($asset);
        $update = $workOrder->updates()->create(['changed_by' => $admin->id, 'from_status' => 'in_progress', 'to_status' => $status, 'notes' => 'Operational history']);
        $report = $workOrder->reports()->create(['created_by' => $admin->id, 'diagnosis' => 'Device inspection']);
        $photo = $report->photos()->create(['uploaded_by' => $admin->id, 'phase' => 'before', 'storage_path' => 'reports/before.jpg']);
        $invoice = $workOrder->invoices()->create([
            'invoice_number' => 'INV-DELETE', 'customer_id' => $asset->site->customer_id, 'created_by' => $admin->id, 'bill_to_name' => 'PT Asset Testing',
        ]);

        $this->actingAs($admin)->from(route('admin.assets.show', $asset))->delete(route('admin.assets.destroy', $asset))
            ->assertRedirectToRoute('admin.assets.show', $asset)->assertSessionHasErrors([
                'asset' => 'Asset tidak dapat dihapus karena masih terkait Work Order atau histori pekerjaan.',
            ]);

        foreach ([$asset, $asset->site, $workOrder, $update, $report, $photo, $invoice] as $record) {
            $this->assertModelExists($record);
        }
        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'asset_code' => 'AST-DELETE', 'customer_site_id' => $workOrder->customer_site_id]);
        $this->assertDatabaseHas('work_order_assets', ['asset_id' => $asset->id, 'work_order_id' => $workOrder->id]);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => $status]);
        $this->assertDatabaseHas('work_order_updates', ['id' => $update->id, 'notes' => 'Operational history']);
        $this->assertDatabaseHas('service_reports', ['id' => $report->id, 'diagnosis' => 'Device inspection']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workOrderStatuses(): array
    {
        return ['active work' => ['in_progress'], 'completed history' => ['closed']];
    }

    public function test_foreign_key_rejection_returns_field_error_and_rolls_back_delete_side_effects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createAsset();
        $workOrder = $this->createWorkOrder($asset, $admin);
        $originalDispatcher = Asset::getEventDispatcher();
        Asset::setEventDispatcher(clone $originalDispatcher);
        try {
            Asset::deleting(static function (Asset $deletingAsset) use ($workOrder): void {
                $workOrder->assets()->attach($deletingAsset);
            });

            $response = $this->actingAs($admin)->from(route('admin.assets.show', $asset))->delete(route('admin.assets.destroy', $asset));
        } finally {
            Asset::setEventDispatcher($originalDispatcher);
        }

        $response->assertRedirectToRoute('admin.assets.show', $asset)->assertSessionHasErrors([
            'asset' => 'Asset tidak dapat dihapus karena masih terkait Work Order atau histori pekerjaan.',
        ]);
        $this->assertModelExists($asset);
        $this->assertModelExists($workOrder);
        $this->assertDatabaseMissing('work_order_assets', ['asset_id' => $asset->id, 'work_order_id' => $workOrder->id]);
    }

    public function test_rejected_delete_from_filtered_index_preserves_search_context_and_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createAsset();
        $workOrder = $this->createWorkOrder($asset, $admin);
        $workOrder->assets()->attach($asset);
        $index = route('admin.assets.index', ['search' => 'AST-DELETE']);

        $this->actingAs($admin)->from($index)->delete(route('admin.assets.destroy', $asset))->assertRedirect($index);

        $this->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('errors.asset', 'Asset tidak dapat dihapus karena masih terkait Work Order atau histori pekerjaan.')
            ->where('filters.search', 'AST-DELETE')->where('assets.total', 1)->etc());
        $this->assertModelExists($asset);
    }

    public function test_guest_cannot_delete_asset(): void
    {
        $asset = $this->createAsset();

        $this->delete(route('admin.assets.destroy', $asset))->assertRedirectToRoute('login');

        $this->assertModelExists($asset);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_roles_other_than_admin_cannot_delete_asset(?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $asset = $this->createAsset();

        $this->actingAs($user)->delete(route('admin.assets.destroy', $asset))->assertForbidden();

        $this->assertModelExists($asset);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function nonAdminRoles(): array
    {
        return ['coordinator' => ['koordinator'], 'technician' => ['teknisi'], 'no role' => [null], 'legacy finance' => ['finance'], 'legacy manager' => ['manager']];
    }

    #[DataProvider('blockedAccounts')]
    public function test_inactive_or_unapproved_admin_cannot_delete_asset(bool $active, string $approval): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => $active, 'approval_status' => $approval]);
        $asset = $this->createAsset();

        $this->actingAs($admin)->delete(route('admin.assets.destroy', $asset))->assertRedirectToRoute('login');

        $this->assertGuest();
        $this->assertModelExists($asset);
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function blockedAccounts(): array
    {
        return ['inactive' => [false, 'approved'], 'pending' => [true, 'pending'], 'rejected' => [true, 'rejected']];
    }

    public function test_missing_or_already_deleted_asset_returns_404(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createAsset();
        $this->actingAs($admin)->delete(route('admin.assets.destroy', $asset))->assertRedirectToRoute('admin.assets.index');

        $this->delete(route('admin.assets.destroy', $asset))->assertNotFound();
        $this->delete(route('admin.assets.destroy', 999999))->assertNotFound();

        $this->assertDatabaseCount('assets', 0);
    }

    private function createAsset(): Asset
    {
        return Customer::create(['name' => 'PT Asset Testing'])->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi'])
            ->assets()->create(['asset_code' => 'AST-DELETE', 'category' => 'cctv', 'brand' => 'Brand', 'model' => 'Model', 'serial_number' => 'SN-DELETE']);
    }

    private function createWorkOrder(Asset $asset, User $creator): WorkOrder
    {
        return WorkOrder::create([
            'wo_number' => 'WO-DELETE', 'customer_site_id' => $asset->customer_site_id, 'created_by' => $creator->id,
            'title' => 'Maintenance', 'description' => 'Inspect device', 'service_type' => 'maintenance', 'category' => 'cctv',
        ]);
    }
}
