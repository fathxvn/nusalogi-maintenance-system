<?php

namespace Tests\Feature\Admin;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_provides_named_search_reset_and_create_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.assets.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/assets/index')
                ->where('links.index', route('admin.assets.index'))
                ->where('links.create', route('admin.assets.create'))
                ->where('filters.search', '')
                ->has('assets.data', 0)->etc());
    }

    public function test_admin_stores_asset_at_selected_site_with_success_feedback_and_serialized_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $otherSite = $this->createSite();
        $data = $this->assetData($site);

        $this->actingAs($admin)->post(route('admin.assets.store'), $data)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.index')
            ->assertSessionHas('success', 'Asset berhasil ditambahkan.');

        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseHas('assets', [...$data, 'status' => 'active']);
        $this->assertDatabaseMissing('assets', ['customer_site_id' => $otherSite->id]);
        $this->get(route('admin.assets.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Asset berhasil ditambahkan.')
                ->where('assets.data.0.links.show', route('admin.assets.show', Asset::query()->firstOrFail()))
                ->where('assets.data.0.installed_at', '2026-11-06')
                ->where('assets.data.0.site', ['id' => $site->id, 'site_name' => 'Office', 'customer_name' => 'PT Asset Testing'])->etc());
    }

    public function test_asset_navigation_is_active_on_create_and_uses_inertia(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.assets.create'))->assertOk();

        $items = collect($response->inertiaProps('navigation'))->keyBy('id');
        $this->assertSame(route('admin.assets.index'), $items['assets']['href']);
        $this->assertTrue($items['assets']['active']);
        $this->assertTrue($items['assets']['inertia']);
        $this->assertFalse($items['customers']['active']);
    }

    public function test_create_form_lists_customer_site_pairs_in_stable_order_with_named_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = $this->createSite();
        $warehouse->update(['site_name' => 'Warehouse']);
        $first = $this->createSite();
        $second = Customer::create(['name' => 'PT Other Customer'])->sites()->create(['site_name' => 'Office', 'address' => 'Jakarta']);

        $this->actingAs($admin)->get(route('admin.assets.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/assets/create')
                ->where('sites', [
                    ['id' => $first->id, 'site_name' => 'Office', 'customer_name' => 'PT Asset Testing'],
                    ['id' => $second->id, 'site_name' => 'Office', 'customer_name' => 'PT Other Customer'],
                    ['id' => $warehouse->id, 'site_name' => 'Warehouse', 'customer_name' => 'PT Asset Testing'],
                ])
                ->where('links.index', route('admin.assets.index'))
                ->where('links.store', route('admin.assets.store'))->etc());
    }

    public function test_create_form_handles_no_sites(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.assets.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sites', 0)->etc());
    }

    #[DataProvider('optionalFields')]
    public function test_optional_fields_can_be_omitted_or_empty_and_date_serializes_as_null(array $optional): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();

        $this->actingAs($admin)->post(route('admin.assets.store'), [
            'customer_site_id' => $site->id, 'asset_code' => 'MINIMAL', 'category' => 'networking', ...$optional,
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.index');

        $this->assertDatabaseHas('assets', [
            'asset_code' => 'MINIMAL', 'customer_site_id' => $site->id, 'status' => 'active',
            'brand' => null, 'model' => null, 'serial_number' => null, 'installed_at' => null,
        ]);
        $this->get(route('admin.assets.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('assets.data.0.installed_at', null)
                ->where('assets.data.0.brand', null)->where('assets.data.0.model', null)
                ->where('assets.data.0.serial_number', null)->etc());
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function optionalFields(): array
    {
        return ['omitted' => [[]], 'empty' => [['brand' => '', 'model' => '', 'serial_number' => '', 'installed_at' => '']]];
    }

    public function test_unvalidated_payload_cannot_override_default_status_identity_or_timestamps(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();

        $this->actingAs($admin)->post(route('admin.assets.store'), [
            ...$this->assetData($site), 'status' => 'tampered', 'id' => 999999,
            'created_at' => '2000-01-01', 'customer_id' => 999999,
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.index');

        $asset = Asset::query()->firstOrFail();
        $this->assertSame('active', $asset->status);
        $this->assertSame($site->id, $asset->customer_site_id);
        $this->assertSame('2026-11-06', $asset->created_at->toDateString());
        $this->assertNotSame(999999, $asset->id);
        $this->assertDatabaseCount('assets', 1);
    }

    public function test_required_fields_return_validation_messages_without_creating_an_asset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->from(route('admin.assets.create'))->post(route('admin.assets.store'), [])
            ->assertRedirectToRoute('admin.assets.create')->assertSessionHasErrors([
                'customer_site_id' => 'The customer site id field is required.',
                'asset_code' => 'The asset code field is required.',
                'category' => 'The category field is required.',
            ]);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_duplicate_asset_code_is_rejected_without_changing_the_existing_asset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $site->assets()->create($this->assetData($site));

        $this->actingAs($admin)->from(route('admin.assets.create'))->post(route('admin.assets.store'), [
            ...$this->assetData($site), 'category' => 'tampered',
        ])->assertRedirectToRoute('admin.assets.create')->assertSessionHasErrors([
            'asset_code' => 'The asset code has already been taken.',
        ]);

        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseHas('assets', $this->assetData($site));
    }

    /**
     * @param  array<string, mixed>  $invalid
     */
    #[DataProvider('invalidAssetData')]
    public function test_invalid_asset_data_is_rejected_without_saving(array $invalid, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();

        $this->actingAs($admin)->from(route('admin.assets.create'))->post(route('admin.assets.store'), [...$this->assetData($site), ...$invalid])
            ->assertRedirectToRoute('admin.assets.create')->assertSessionHasErrors([$field => $message]);

        $this->assertDatabaseCount('assets', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidAssetData(): array
    {
        $cases = [
            'missing site' => [['customer_site_id' => 999999], 'customer_site_id', 'The selected customer site id is invalid.'],
            'noninteger site' => [['customer_site_id' => 'wrong'], 'customer_site_id', 'The customer site id field must be an integer.'],
            'site array' => [['customer_site_id' => [1]], 'customer_site_id', 'The customer site id field must be an integer.'],
            'impossible date' => [['installed_at' => '2026-02-30'], 'installed_at', 'The installed at field must match the format Y-m-d.'],
            'wrong date format' => [['installed_at' => '06/11/2026'], 'installed_at', 'The installed at field must match the format Y-m-d.'],
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

    public function test_unicode_at_column_limits_is_saved_and_leap_day_is_valid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $data = [
            'customer_site_id' => $site->id, 'asset_code' => str_repeat('é', 50), 'category' => str_repeat('設', 100),
            'brand' => str_repeat('é', 100), 'model' => str_repeat('é', 100), 'serial_number' => str_repeat('é', 150), 'installed_at' => '2024-02-29',
        ];

        $this->actingAs($admin)->post(route('admin.assets.store'), $data)->assertSessionHasNoErrors()->assertRedirectToRoute('admin.assets.index');

        $this->assertDatabaseHas('assets', $data);
        $this->assertSame('2024-02-29', Asset::query()->firstOrFail()->installed_at->toDateString());
    }

    #[DataProvider('searchableFields')]
    public function test_search_matches_only_the_expected_asset(string $field): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $match = $site->assets()->create([...$this->assetData($site), $field => 'José']);
        $site->assets()->create([...$this->assetData($site), 'asset_code' => 'UNRELATED']);

        $this->actingAs($admin)->get(route('admin.assets.index', ['search' => ' José ']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.search', 'José')
                ->where('assets.total', 1)->has('assets.data', 1)->where('assets.data.0.id', $match->id)->etc());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function searchableFields(): array
    {
        return ['code' => ['asset_code'], 'category' => ['category'], 'brand' => ['brand'], 'model' => ['model'], 'serial' => ['serial_number']];
    }

    public function test_pagination_preserves_search_and_newest_first_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        for ($index = 1; $index <= 12; $index++) {
            $site->assets()->create([...$this->assetData($site), 'asset_code' => sprintf('MATCH-%02d', $index)]);
        }
        $site->assets()->create([...$this->assetData($site), 'asset_code' => 'UNRELATED']);

        $first = $this->actingAs($admin)->get(route('admin.assets.index', ['search' => 'MATCH-']))->assertOk();

        $first->assertInertia(fn (Assert $page) => $page->where('assets.total', 12)->has('assets.data', 10)
            ->where('assets.data.0.asset_code', 'MATCH-12')->where('assets.per_page', 10)
            ->where('assets.prev_page_url', null)->where('assets.last_page', 2)->etc());
        $next = $first->inertiaProps('assets.next_page_url');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'MATCH-', 'page' => '2'], $query);
        $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page->has('assets.data', 2)
            ->where('assets.current_page', 2)->where('assets.next_page_url', null)
            ->where('assets.data.0.asset_code', 'MATCH-02')->where('filters.search', 'MATCH-')->etc());
    }

    #[DataProvider('unmatchedSearches')]
    public function test_unmatched_search_returns_no_assets(string $search): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $site->assets()->create($this->assetData($site));

        $this->actingAs($admin)->get(route('admin.assets.index', ['search' => $search]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('assets.total', 0)->has('assets.data', 0)
                ->where('filters.search', $search)->etc());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unmatchedSearches(): array
    {
        return ['no match' => ['Unknown'], 'SQL-like value' => ["' OR 1=1 --"]];
    }

    #[DataProvider('invalidSearches')]
    public function test_invalid_search_redirects_with_validation_feedback(array|string $search, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->from(route('admin.assets.index'))->get(route('admin.assets.index', ['search' => $search]))
            ->assertRedirectToRoute('admin.assets.index')->assertSessionHasErrors(['search' => $message]);
    }

    /**
     * @return array<string, array{0: array<string>|string, 1: string}>
     */
    public static function invalidSearches(): array
    {
        return [
            'array' => [['invalid'], 'The search field must be a string.'],
            'length' => [str_repeat('a', 101), 'The search field must not be greater than 100 characters.'],
            'encoding' => ["\xFF", 'The search field must be encoded in UTF-8.'],
        ];
    }

    public function test_list_eager_loads_sites_and_customers_with_bounded_queries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        for ($index = 1; $index <= 10; $index++) {
            $site = $this->createSite();
            $site->assets()->create([...$this->assetData($site), 'asset_code' => 'ASSET-'.$index]);
        }
        $this->actingAs($admin);
        DB::enableQueryLog();
        try {
            $response = $this->get(route('admin.assets.index'))->assertOk();
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertInertia(fn (Assert $page) => $page->has('assets.data', 10)
            ->where('assets.data.0.site.customer_name', 'PT Asset Testing')->etc());
        $this->assertLessThanOrEqual(8, $queryCount);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return ['index' => ['get', 'index'], 'create' => ['get', 'create'], 'store' => ['post', 'store']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_cannot_access_asset_endpoints_or_save_assets(string $method, string $action): void
    {
        $site = $this->createSite();

        $this->call(strtoupper($method), route('admin.assets.'.$action), $this->assetData($site))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('assets', 0);
    }

    #[DataProvider('nonAdminEndpoints')]
    public function test_non_admin_cannot_access_asset_endpoints_or_save_assets(string $method, string $action, ?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $site = $this->createSite();

        $this->actingAs($user)->call(strtoupper($method), route('admin.assets.'.$action), $this->assetData($site))->assertForbidden();

        $this->assertDatabaseCount('assets', 0);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function nonAdminEndpoints(): array
    {
        $data = [];
        foreach (self::endpoints() as $action => [$method, $route]) {
            foreach (['koordinator', 'teknisi', 'finance', 'manager', null] as $role) {
                $data[$action.' '.($role ?? 'no role')] = [$method, $route, $role];
            }
        }

        return $data;
    }

    #[DataProvider('blockedEndpoints')]
    public function test_inactive_or_unapproved_admin_is_signed_out_without_saving_assets(string $method, string $action, bool $active, string $approval): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => $active, 'approval_status' => $approval]);
        $site = $this->createSite();

        $this->actingAs($admin)->call(strtoupper($method), route('admin.assets.'.$action), $this->assetData($site))->assertRedirectToRoute('login');

        $this->assertGuest();
        $this->assertDatabaseCount('assets', 0);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function blockedEndpoints(): array
    {
        $data = [];
        foreach (self::endpoints() as $action => [$method, $route]) {
            foreach (['inactive' => [false, 'approved'], 'pending' => [true, 'pending'], 'rejected' => [true, 'rejected']] as $label => [$active, $approval]) {
                $data[$action.' '.$label] = [$method, $route, $active, $approval];
            }
        }

        return $data;
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_sidebar_does_not_link_to_assets(?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get(route('migration.test'))->assertOk();

        $items = collect($response->inertiaProps('navigation'))->keyBy('id');
        $this->assertNull($items['assets']['href']);
        $this->assertFalse($items['assets']['active']);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function nonAdminRoles(): array
    {
        return ['coordinator' => ['koordinator'], 'technician' => ['teknisi'], 'no role' => [null]];
    }

    public function test_admin_receives_detail_fields_customer_site_date_and_back_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));
        $otherSite = Customer::create(['name' => 'Unrelated Customer'])->sites()->create(['site_name' => 'Other Site', 'address' => 'Jakarta']);
        $otherSite->assets()->create([...$this->assetData($otherSite), 'asset_code' => 'UNRELATED']);

        $response = $this->actingAs($admin)->get(route('admin.assets.show', $asset))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('admin/assets/show')
            ->where('asset', [
                'id' => $asset->id, 'asset_code' => 'AST-001', 'category' => 'cctv',
                'brand' => 'Brand', 'model' => 'Model 1', 'serial_number' => 'SN-001',
                'status' => 'active', 'installed_at' => '2026-11-06',
                'site' => ['id' => $site->id, 'site_name' => 'Office', 'customer_name' => 'PT Asset Testing'],
            ])
            ->where('links.index', route('admin.assets.index'))->etc());
        $items = collect($response->inertiaProps('navigation'))->keyBy('id');
        $this->assertTrue($items['assets']['active']);
        $this->assertTrue($items['assets']['inertia']);
        $this->assertFalse($items['customers']['active']);
    }

    public function test_detail_preserves_null_optional_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $asset = $site->assets()->create(['asset_code' => 'MINIMAL', 'category' => 'networking']);

        $this->actingAs($admin)->get(route('admin.assets.show', $asset))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('asset.brand', null)->where('asset.model', null)
                ->where('asset.serial_number', null)->where('asset.installed_at', null)->etc());
    }

    public function test_detail_of_a_missing_asset_returns_404(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.assets.show', 999999))->assertNotFound();
    }

    public function test_guest_is_redirected_from_asset_detail(): void
    {
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->get(route('admin.assets.show', $asset))->assertRedirectToRoute('login');
    }

    #[DataProvider('detailForbiddenRoles')]
    public function test_non_admin_cannot_read_asset_detail(?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($user)->get(route('admin.assets.show', $asset))->assertForbidden();
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function detailForbiddenRoles(): array
    {
        return [
            ...self::nonAdminRoles(), 'legacy finance' => ['finance'], 'legacy manager' => ['manager'],
        ];
    }

    #[DataProvider('blockedDetailAccounts')]
    public function test_inactive_or_unapproved_admin_is_signed_out_before_reading_detail(bool $active, string $approval): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => $active, 'approval_status' => $approval]);
        $site = $this->createSite();
        $asset = $site->assets()->create($this->assetData($site));

        $this->actingAs($admin)->get(route('admin.assets.show', $asset))->assertRedirectToRoute('login');

        $this->assertGuest();
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function blockedDetailAccounts(): array
    {
        return ['inactive' => [false, 'approved'], 'pending' => [true, 'pending'], 'rejected' => [true, 'rejected']];
    }

    private function createSite(): CustomerSite
    {
        return Customer::create(['name' => 'PT Asset Testing'])->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
    }

    /**
     * @return array{customer_site_id: int, asset_code: string, category: string, brand: string, model: string, serial_number: string, installed_at: string}
     */
    private function assetData(CustomerSite $site): array
    {
        return [
            'customer_site_id' => $site->id, 'asset_code' => 'AST-001', 'category' => 'cctv',
            'brand' => 'Brand', 'model' => 'Model 1', 'serial_number' => 'SN-001', 'installed_at' => '2026-11-06',
        ];
    }
}
