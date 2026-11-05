<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerSiteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_create_and_edit_forms_with_customer_identity_and_current_site_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'PT Site Testing']);
        $site = $customer->sites()->create($this->siteData());

        $this->actingAs($admin)->get(route('admin.customers.sites.create', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/customers/sites/create')
                ->where('customer', ['id' => $customer->id, 'name' => 'PT Site Testing'])
                ->where('links.show', route('admin.customers.show', $customer))
                ->where('links.store', route('admin.customers.sites.store', $customer))->etc());
        $this->get(route('admin.customers.sites.edit', [$customer, $site]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/customers/sites/edit')
                ->where('customer', ['id' => $customer->id, 'name' => 'PT Site Testing'])
                ->where('site', ['id' => $site->id, ...$this->siteData()])
                ->where('links.show', route('admin.customers.show', $customer))
                ->where('links.update', route('admin.customers.sites.update', [$customer, $site]))->etc());
    }

    public function test_admin_creates_site_and_returns_to_detail_with_success_message_and_site_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'PT Site Testing']);
        Customer::create(['name' => 'Other Customer'])->sites()->create(['site_name' => 'Unrelated Site', 'address' => 'Jakarta']);

        $response = $this->actingAs($admin)->post(route('admin.customers.sites.store', $customer), $this->siteData());

        $response->assertRedirectToRoute('admin.customers.show', $customer)
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Site berhasil ditambahkan.');
        $this->assertDatabaseHas('customer_sites', ['customer_id' => $customer->id, ...$this->siteData()]);
        $this->assertDatabaseCount('customer_sites', 2);
        $site = $customer->sites()->firstOrFail();
        $this->get(route('admin.customers.show', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/customers/show')
                ->where('flash.success', 'Site berhasil ditambahkan.')
                ->where('customer.sites_count', 1)->has('customer.sites', 1)
                ->where('customer.sites.0', [
                    'id' => $site->id, ...$this->siteData(),
                    'links' => ['edit' => route('admin.customers.sites.edit', [$customer, $site])],
                ])
                ->where('links.createSite', route('admin.customers.sites.create', $customer))->etc());
    }

    public function test_admin_updates_site_without_changing_its_customer_or_related_assets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'PT Site Testing']);
        $site = $customer->sites()->create($this->siteData());
        $asset = $site->assets()->create(['asset_code' => 'SITE-UPDATE', 'category' => 'cctv']);
        $sibling = $customer->sites()->create(['site_name' => 'Sibling Site', 'address' => 'Jakarta']);
        $updated = ['site_name' => 'Updated Office', 'address' => "Jl. Baru 2\nBandung", 'pic_name' => 'Siti', 'pic_phone' => '+62 813'];

        $this->actingAs($admin)->patch(route('admin.customers.sites.update', [$customer, $site]), $updated)
            ->assertRedirectToRoute('admin.customers.show', $customer)
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Site berhasil diperbarui.');

        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, 'customer_id' => $customer->id, ...$updated]);
        $this->assertDatabaseHas('customer_sites', ['id' => $sibling->id, 'site_name' => 'Sibling Site', 'address' => 'Jakarta']);
        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'customer_site_id' => $site->id]);
        $this->get(route('admin.customers.show', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Site berhasil diperbarui.')
                ->where('customer.sites.1.site_name', 'Updated Office')
                ->where('customer.sites.1.address', "Jl. Baru 2\nBandung")->etc());
    }

    public function test_pic_fields_can_be_omitted_on_create_and_cleared_on_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'PT Site Testing']);

        $this->actingAs($admin)->post(route('admin.customers.sites.store', $customer), ['site_name' => 'Minimal Site', 'address' => 'Bekasi'])
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.customers.show', $customer);

        $this->assertDatabaseHas('customer_sites', ['customer_id' => $customer->id, 'pic_name' => null, 'pic_phone' => null]);
        $site = $customer->sites()->create($this->siteData());
        $this->patch(route('admin.customers.sites.update', [$customer, $site]), [...$this->siteData(), 'pic_name' => '', 'pic_phone' => ''])
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.customers.show', $customer);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, 'pic_name' => null, 'pic_phone' => null]);
    }

    public function test_customer_id_and_other_unvalidated_attributes_cannot_override_route_ownership_or_site_identity(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'URL Customer']);
        $otherCustomer = Customer::create(['name' => 'Payload Customer']);
        $payload = [...$this->siteData(), 'customer_id' => $otherCustomer->id, 'id' => 999999, 'created_at' => '2000-01-01'];

        $this->actingAs($admin)->post(route('admin.customers.sites.store', $customer), $payload)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.customers.show', $customer);

        $site = $customer->sites()->firstOrFail();
        $this->assertNotSame(999999, $site->id);
        $this->assertSame('2026-11-06', $site->created_at->toDateString());
        $this->patch(route('admin.customers.sites.update', [$customer, $site]), [...$payload, 'site_name' => 'Updated Site'])
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.customers.show', $customer);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, 'customer_id' => $customer->id, 'site_name' => 'Updated Site']);
        $this->assertSame('2026-11-06', $site->fresh()->created_at->toDateString());
        $this->assertDatabaseMissing('customer_sites', ['customer_id' => $otherCustomer->id]);
        $this->assertDatabaseMissing('customer_sites', ['id' => 999999]);
        $this->assertDatabaseCount('customer_sites', 1);
    }

    /**
     * @param  array<string, mixed>  $invalid
     */
    #[DataProvider('invalidSiteData')]
    public function test_invalid_data_returns_field_error_without_creating_or_changing_sites(string $method, array $invalid, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'PT Site Testing']);
        $site = $customer->sites()->create($this->siteData());
        $url = $method === 'post' ? route('admin.customers.sites.store', $customer) : route('admin.customers.sites.update', [$customer, $site]);

        $this->actingAs($admin)->from(route('admin.customers.show', $customer))
            ->{$method}($url, [...$this->siteData(), ...$invalid])
            ->assertRedirectToRoute('admin.customers.show', $customer)->assertSessionHasErrors([$field => $message]);

        $this->assertDatabaseCount('customer_sites', 1);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, 'customer_id' => $customer->id, ...$this->siteData()]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function invalidSiteData(): array
    {
        $cases = [
            'required name' => [['site_name' => '  '], 'site_name', 'The site name field is required.'],
            'required address' => [['address' => '  '], 'address', 'The address field is required.'],
            'null address' => [['address' => null], 'address', 'The address field is required.'],
            'name length' => [['site_name' => str_repeat('é', 151)], 'site_name', 'The site name field must not be greater than 150 characters.'],
            'PIC length' => [['pic_name' => str_repeat('é', 151)], 'pic_name', 'The pic name field must not be greater than 150 characters.'],
            'phone length' => [['pic_phone' => str_repeat('1', 31)], 'pic_phone', 'The pic phone field must not be greater than 30 characters.'],
            'ASCII address capacity' => [['address' => str_repeat('a', 65536)], 'address', 'Alamat site maksimal 65.535 byte.'],
            'UTF-8 address capacity' => [['address' => str_repeat('é', 32768)], 'address', 'Alamat site maksimal 65.535 byte.'],
            'four-byte address capacity' => [['address' => str_repeat('🏢', 16384)], 'address', 'Alamat site maksimal 65.535 byte.'],
        ];
        foreach (['site_name', 'address', 'pic_name', 'pic_phone'] as $field) {
            $label = str_replace('_', ' ', $field);
            $cases[$field.' type'] = [[$field => ['invalid']], $field, 'The '.$label.' field must be a string.'];
            $cases[$field.' encoding'] = [[$field => "\xFF"], $field, 'The '.$label.' field must be encoded in UTF-8.'];
        }
        $data = [];
        foreach (['post', 'patch'] as $method) {
            foreach ($cases as $label => [$invalid, $field, $message]) {
                $data[$method.' '.$label] = [$method, $invalid, $field, $message];
            }
        }

        return $data;
    }

    #[DataProvider('validBoundaries')]
    public function test_database_length_boundaries_and_unicode_are_persisted_on_create_and_update(string $address): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'PT Site Testing']);
        $data = ['site_name' => str_repeat('🏢', 150), 'address' => $address, 'pic_name' => str_repeat('é', 150), 'pic_phone' => str_repeat('1', 30)];

        $this->actingAs($admin)->post(route('admin.customers.sites.store', $customer), $data)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.customers.show', $customer);

        $site = $customer->sites()->firstOrFail();
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, 'customer_id' => $customer->id, ...$data]);
        $this->patch(route('admin.customers.sites.update', [$customer, $site]), [...$data, 'site_name' => 'Updated Boundary'])
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.customers.show', $customer);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, 'customer_id' => $customer->id, ...$data, 'site_name' => 'Updated Boundary']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validBoundaries(): array
    {
        return [
            'ASCII 65535 bytes' => [str_repeat('a', 65535)],
            'UTF-8 65535 bytes' => [str_repeat('é', 32767).'a'],
            'four-byte UTF-8 65535 bytes' => [str_repeat('🏢', 16383).'abc'],
        ];
    }

    #[DataProvider('editEndpoints')]
    public function test_site_belonging_to_another_customer_returns_404_even_with_forged_customer_id(string $method, string $action): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'URL Customer']);
        $owner = Customer::create(['name' => 'Actual Owner']);
        $site = $owner->sites()->create($this->siteData());

        $this->actingAs($admin)->call(strtoupper($method), route('admin.customers.sites.'.$action, [$customer, $site]), [
            ...$this->siteData(), 'customer_id' => $owner->id, 'site_name' => 'Tampered Site',
        ])->assertNotFound();

        $this->assertDatabaseCount('customer_sites', 1);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, 'customer_id' => $owner->id, ...$this->siteData()]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function editEndpoints(): array
    {
        return ['edit' => ['get', 'edit'], 'update' => ['patch', 'update']];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function siteEndpoints(): array
    {
        return ['create' => ['get', 'create'], 'store' => ['post', 'store'], ...self::editEndpoints()];
    }

    #[DataProvider('siteEndpoints')]
    public function test_guest_is_redirected_to_login_without_mutating_sites(string $method, string $action): void
    {
        $customer = Customer::create(['name' => 'PT Site Testing']);
        $site = $customer->sites()->create($this->siteData());

        $this->call(strtoupper($method), route('admin.customers.sites.'.$action, ['customer' => $customer->id, 'site' => $site->id]), [
            ...$this->siteData(), 'site_name' => 'Unauthorized Change',
        ])->assertRedirectToRoute('login');

        $this->assertDatabaseCount('customer_sites', 1);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, ...$this->siteData()]);
    }

    #[DataProvider('nonAdminEndpoints')]
    public function test_roles_other_than_admin_receive_403_without_mutating_sites(string $method, string $action, ?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $customer = Customer::create(['name' => 'PT Site Testing']);
        $site = $customer->sites()->create($this->siteData());

        $this->actingAs($user)->call(strtoupper($method), route('admin.customers.sites.'.$action, ['customer' => $customer->id, 'site' => $site->id]), [
            ...$this->siteData(), 'site_name' => 'Unauthorized Change',
        ])->assertForbidden();

        $this->assertDatabaseCount('customer_sites', 1);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, ...$this->siteData()]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function nonAdminEndpoints(): array
    {
        $data = [];
        foreach (self::siteEndpoints() as $action => [$method, $route]) {
            foreach (['koordinator', 'teknisi', 'finance', 'manager', null] as $role) {
                $data[$action.' '.($role ?? 'no role')] = [$method, $route, $role];
            }
        }

        return $data;
    }

    #[DataProvider('blockedAccounts')]
    public function test_inactive_or_unapproved_admin_is_signed_out_without_mutating_sites(string $method, string $action, bool $isActive, string $approvalStatus): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => $isActive, 'approval_status' => $approvalStatus]);
        $customer = Customer::create(['name' => 'PT Site Testing']);
        $site = $customer->sites()->create($this->siteData());

        $this->actingAs($user)->call(strtoupper($method), route('admin.customers.sites.'.$action, ['customer' => $customer->id, 'site' => $site->id]), [
            ...$this->siteData(), 'site_name' => 'Unauthorized Change',
        ])->assertRedirectToRoute('login');

        $this->assertGuest();
        $this->assertDatabaseCount('customer_sites', 1);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, ...$this->siteData()]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function blockedAccounts(): array
    {
        $data = [];
        foreach (self::siteEndpoints() as $action => [$method, $route]) {
            foreach (['inactive' => [false, 'approved'], 'pending' => [true, 'pending'], 'rejected' => [true, 'rejected']] as $label => [$active, $approval]) {
                $data[$action.' '.$label] = [$method, $route, $active, $approval];
            }
        }

        return $data;
    }

    #[DataProvider('siteEndpoints')]
    public function test_missing_customer_returns_404_without_mutating_sites(string $method, string $action): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Existing Customer']);
        $site = $customer->sites()->create($this->siteData());

        $this->actingAs($admin)->call(strtoupper($method), route('admin.customers.sites.'.$action, ['customer' => 999999, 'site' => $site->id]), $this->siteData())
            ->assertNotFound();

        $this->assertDatabaseCount('customer_sites', 1);
        $this->assertDatabaseHas('customer_sites', ['id' => $site->id, ...$this->siteData()]);
    }

    #[DataProvider('editEndpoints')]
    public function test_missing_site_returns_404(string $method, string $action): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Existing Customer']);

        $this->actingAs($admin)->call(strtoupper($method), route('admin.customers.sites.'.$action, [$customer, 999999]), $this->siteData())
            ->assertNotFound();

        $this->assertDatabaseCount('customer_sites', 0);
    }

    /**
     * @return array{site_name: string, address: string, pic_name: string, pic_phone: string}
     */
    private function siteData(): array
    {
        return ['site_name' => 'Main Office', 'address' => "Jalan Testing 1\nBekasi", 'pic_name' => 'José Wijaya', 'pic_phone' => '+62 812 1234 5678'];
    }
}
