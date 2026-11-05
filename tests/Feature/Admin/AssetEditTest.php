<?php

namespace Tests\Feature\Admin;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_returns_selected_asset_site_options_named_links_and_active_navigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $otherSite = Customer::create(['name' => 'PT Other Customer'])->sites()->create(['site_name' => 'Warehouse', 'address' => 'Jakarta']);
        $asset = $site->assets()->create($this->assetData($site));

        $response = $this->actingAs($admin)->get(route('admin.assets.edit', $asset))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('admin/assets/edit')
            ->where('asset', [
                'id' => $asset->id, 'asset_code' => 'AST-EDIT', 'category' => 'cctv', 'brand' => 'Brand',
                'model' => 'Model 1', 'serial_number' => 'SN-001', 'status' => 'active', 'installed_at' => '2026-11-06',
                'site' => ['id' => $site->id, 'site_name' => 'Office', 'customer_name' => 'PT Asset Testing'],
            ])
            ->where('sites', [
                ['id' => $site->id, 'site_name' => 'Office', 'customer_name' => 'PT Asset Testing'],
                ['id' => $otherSite->id, 'site_name' => 'Warehouse', 'customer_name' => 'PT Other Customer'],
            ])
            ->where('links.show', route('admin.assets.show', $asset))
            ->where('links.update', route('admin.assets.update', $asset))->etc());
        $navigation = collect($response->inertiaProps('navigation'))->keyBy('id');
        $this->assertSame(route('admin.assets.index'), $navigation['assets']['href']);
        $this->assertTrue($navigation['assets']['active']);
        $this->assertTrue($navigation['assets']['inertia']);
        $this->get(route('admin.assets.show', $asset))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.edit', route('admin.assets.edit', $asset))->etc());
    }

    public function test_edit_page_preserves_nullable_values_for_the_shared_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = $this->createSite()->assets()->create(['asset_code' => 'MINIMAL', 'category' => 'networking']);

        $this->actingAs($admin)->get(route('admin.assets.edit', $asset))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('asset.brand', null)->where('asset.model', null)
                ->where('asset.serial_number', null)->where('asset.installed_at', null)->etc());
    }

    public function test_update_saves_all_fields_and_can_move_an_asset_without_work_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $newSite = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));
        $other = $site->assets()->create([...$this->assetData($site), 'asset_code' => 'OTHER']);
        $updated = [
            'customer_site_id' => $newSite->id, 'asset_code' => 'UPDATED', 'category' => 'networking',
            'brand' => 'New Brand', 'model' => 'New Model', 'serial_number' => 'SN-NEW', 'installed_at' => '2024-02-29',
        ];

        $this->actingAs($admin)->patch(route('admin.assets.update', $asset), $updated)
            ->assertRedirectToRoute('admin.assets.show', $asset)->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Asset berhasil diperbarui.');

        $this->assertDatabaseCount('assets', 2);
        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$updated, 'status' => 'active']);
        $this->assertDatabaseHas('assets', ['id' => $other->id, ...$this->assetData($site), 'asset_code' => 'OTHER']);
        $this->get(route('admin.assets.show', $asset))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Asset berhasil diperbarui.')->where('asset.asset_code', 'UPDATED')
            ->where('asset.site.id', $newSite->id)->where('asset.installed_at', '2024-02-29')->etc());
    }

    public function test_own_asset_code_is_accepted_and_unvalidated_attributes_are_ignored(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($admin)->patch(route('admin.assets.update', $asset), [
            ...$this->assetData($site), 'brand' => 'Updated Brand', 'id' => 999999, 'status' => 'tampered',
            'created_at' => '2000-01-01', 'customer_id' => 999999, 'ignore' => 999999,
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.show', $asset);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'asset_code' => 'AST-EDIT', 'brand' => 'Updated Brand', 'status' => 'active']);
        $this->assertSame('2026-11-06', $asset->fresh()->created_at->toDateString());
        $this->assertDatabaseMissing('assets', ['id' => 999999]);
        $this->assertDatabaseCount('assets', 1);
    }

    public function test_another_assets_code_is_rejected_even_when_payload_forges_its_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));
        $other = $site->assets()->create([...$this->assetData($site), 'asset_code' => 'OTHER']);

        $this->actingAs($admin)->from(route('admin.assets.edit', $asset))->patch(route('admin.assets.update', $asset), [
            ...$this->assetData($site), 'asset_code' => 'OTHER', 'id' => $other->id, 'ignore' => $other->id,
        ])->assertRedirectToRoute('admin.assets.edit', $asset)->assertSessionHasErrors([
            'asset_code' => 'The asset code has already been taken.',
        ]);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$this->assetData($site)]);
        $this->assertDatabaseHas('assets', ['id' => $other->id, 'asset_code' => 'OTHER']);
    }

    public function test_optional_fields_can_be_cleared_including_installation_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($admin)->patch(route('admin.assets.update', $asset), [
            ...$this->assetData($site), 'brand' => '', 'model' => '', 'serial_number' => '', 'installed_at' => '',
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.show', $asset);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'brand' => null, 'model' => null, 'serial_number' => null, 'installed_at' => null]);
    }

    public function test_missing_required_fields_are_rejected_without_mutating_asset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($admin)->from(route('admin.assets.edit', $asset))->patch(route('admin.assets.update', $asset), [])
            ->assertRedirectToRoute('admin.assets.edit', $asset)->assertSessionHasErrors([
                'customer_site_id' => 'The customer site id field is required.',
                'asset_code' => 'The asset code field is required.', 'category' => 'The category field is required.',
            ]);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$this->assetData($site)]);
    }

    /**
     * @param  array<string, mixed>  $invalid
     */
    #[DataProvider('invalidUpdates')]
    public function test_invalid_update_returns_field_error_without_mutating_asset(array $invalid, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($admin)->from(route('admin.assets.edit', $asset))->patch(route('admin.assets.update', $asset), [...$this->assetData($site), ...$invalid])
            ->assertRedirectToRoute('admin.assets.edit', $asset)->assertSessionHasErrors([$field => $message]);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$this->assetData($site)]);
        $this->assertDatabaseCount('assets', 1);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidUpdates(): array
    {
        $cases = [
            'invalid site' => [['customer_site_id' => 999999], 'customer_site_id', 'The selected customer site id is invalid.'],
            'site type' => [['customer_site_id' => 'wrong'], 'customer_site_id', 'The customer site id field must be an integer.'],
            'site array' => [['customer_site_id' => [1]], 'customer_site_id', 'The customer site id field must be an integer.'],
            'invalid date' => [['installed_at' => '2026-02-30'], 'installed_at', 'The installed at field must match the format Y-m-d.'],
            'date format' => [['installed_at' => '06/11/2026'], 'installed_at', 'The installed at field must match the format Y-m-d.'],
            'date array' => [['installed_at' => ['2026-11-06']], 'installed_at', 'The installed at field must match the format Y-m-d.'],
        ];
        foreach (['asset_code' => 50, 'category' => 100, 'brand' => 100, 'model' => 100, 'serial_number' => 150] as $field => $limit) {
            $label = str_replace('_', ' ', $field);
            $cases[$field.' length'] = [[$field => str_repeat('é', $limit + 1)], $field, 'The '.$label.' field must not be greater than '.$limit.' characters.'];
            $cases[$field.' type'] = [[$field => ['invalid']], $field, 'The '.$label.' field must be a string.'];
            $cases[$field.' encoding'] = [[$field => "\xFF"], $field, 'The '.$label.' field must be encoded in UTF-8.'];
        }

        return $cases;
    }

    public function test_update_accepts_column_boundaries_and_unicode(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));
        $data = [
            'customer_site_id' => $site->id, 'asset_code' => str_repeat('é', 50), 'category' => str_repeat('設', 100),
            'brand' => str_repeat('é', 100), 'model' => str_repeat('é', 100), 'serial_number' => str_repeat('é', 150), 'installed_at' => '2024-02-29',
        ];

        $this->actingAs($admin)->patch(route('admin.assets.update', $asset), $data)->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.show', $asset);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$data]);
    }

    #[DataProvider('workOrderStatuses')]
    public function test_site_change_is_rejected_for_work_order_asset_without_partial_updates(string $status): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $newSite = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));
        $workOrder = $this->attachWorkOrder($asset, $admin, $status);

        $this->actingAs($admin)->from(route('admin.assets.edit', $asset))->patch(route('admin.assets.update', $asset), [
            ...$this->assetData($site), 'customer_site_id' => $newSite->id, 'brand' => 'Must Not Save',
        ])->assertRedirectToRoute('admin.assets.edit', $asset)->assertSessionHasErrors([
            'customer_site_id' => 'Lokasi asset yang sudah terkait Work Order tidak dapat dipindahkan.',
        ]);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$this->assetData($site)]);
        $this->assertDatabaseHas('work_order_assets', ['asset_id' => $asset->id, 'work_order_id' => $workOrder->id]);
        $this->assertModelExists($workOrder);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workOrderStatuses(): array
    {
        return ['pending WO' => ['pending'], 'closed WO' => ['closed']];
    }

    public function test_device_data_can_change_on_work_order_asset_when_site_stays_the_same(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));
        $workOrder = $this->attachWorkOrder($asset, $admin);

        $this->actingAs($admin)->patch(route('admin.assets.update', $asset), [
            ...$this->assetData($site), 'customer_site_id' => (string) $site->id, 'model' => 'Updated Model', 'serial_number' => '',
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.show', $asset);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'customer_site_id' => $site->id, 'model' => 'Updated Model', 'serial_number' => null]);
        $this->assertDatabaseHas('work_order_assets', ['asset_id' => $asset->id, 'work_order_id' => $workOrder->id]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function editEndpoints(): array
    {
        return ['edit' => ['get', 'edit'], 'update' => ['patch', 'update']];
    }

    #[DataProvider('editEndpoints')]
    public function test_guest_cannot_edit_or_update_asset(string $method, string $action): void
    {
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->call(strtoupper($method), route('admin.assets.'.$action, $asset), [...$this->assetData($site), 'brand' => 'Unauthorized'])
            ->assertRedirectToRoute('login');

        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$this->assetData($site)]);
    }

    #[DataProvider('nonAdminEndpoints')]
    public function test_non_admin_cannot_edit_or_update_asset(string $method, string $action, ?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($user)->call(strtoupper($method), route('admin.assets.'.$action, $asset), [...$this->assetData($site), 'brand' => 'Unauthorized'])
            ->assertForbidden();

        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$this->assetData($site)]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function nonAdminEndpoints(): array
    {
        $data = [];
        foreach (self::editEndpoints() as $action => [$method, $route]) {
            foreach (['koordinator', 'teknisi', 'finance', 'manager', null] as $role) {
                $data[$action.' '.($role ?? 'no role')] = [$method, $route, $role];
            }
        }

        return $data;
    }

    #[DataProvider('blockedEndpoints')]
    public function test_inactive_or_unapproved_admin_cannot_edit_or_update_asset(string $method, string $action, bool $active, string $approval): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => $active, 'approval_status' => $approval]);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($user)->call(strtoupper($method), route('admin.assets.'.$action, $asset), [...$this->assetData($site), 'brand' => 'Unauthorized'])
            ->assertRedirectToRoute('login');

        $this->assertGuest();
        $this->assertDatabaseHas('assets', ['id' => $asset->id, ...$this->assetData($site)]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function blockedEndpoints(): array
    {
        $data = [];
        foreach (self::editEndpoints() as $action => [$method, $route]) {
            foreach (['inactive' => [false, 'approved'], 'pending' => [true, 'pending'], 'rejected' => [true, 'rejected']] as $label => [$active, $approval]) {
                $data[$action.' '.$label] = [$method, $route, $active, $approval];
            }
        }

        return $data;
    }

    #[DataProvider('editEndpoints')]
    public function test_missing_asset_returns_404(string $method, string $action): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->call(strtoupper($method), route('admin.assets.'.$action, 999999), ['asset_code' => 'Unknown'])->assertNotFound();
    }

    private function createSite(): CustomerSite
    {
        return Customer::create(['name' => 'PT Asset Testing'])->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
    }

    private function attachWorkOrder(Asset $asset, User $creator, string $status = 'pending'): WorkOrder
    {
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-EDIT-'.$asset->id, 'customer_site_id' => $asset->customer_site_id, 'created_by' => $creator->id,
            'title' => 'Maintenance', 'description' => 'Inspect device', 'service_type' => 'maintenance', 'category' => 'cctv',
        ]);
        $workOrder->forceFill(['status' => $status])->save();
        $workOrder->assets()->attach($asset);

        return $workOrder;
    }

    /**
     * @return array{customer_site_id: int, asset_code: string, category: string, brand: string, model: string, serial_number: string, installed_at: string}
     */
    private function assetData(CustomerSite $site): array
    {
        return [
            'customer_site_id' => $site->id, 'asset_code' => 'AST-EDIT', 'category' => 'cctv',
            'brand' => 'Brand', 'model' => 'Model 1', 'serial_number' => 'SN-001', 'installed_at' => '2026-11-06',
        ];
    }
}
