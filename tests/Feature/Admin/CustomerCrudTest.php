<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_create_and_edit_forms_with_current_values_and_named_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create($this->customerData());

        $this->actingAs($admin)->get(route('admin.customers.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/customers/create')
                ->where('links.index', route('admin.customers.index'))
                ->where('links.store', route('admin.customers.store'))->etc());
        $this->get(route('admin.customers.edit', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/customers/edit')
                ->where('customer', ['id' => $customer->id, ...$this->customerData()])
                ->where('links.update', route('admin.customers.update', $customer))->etc());
    }

    public function test_admin_creates_customer_with_all_fields_and_success_feedback(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = $this->customerData();

        $response = $this->actingAs($admin)->post(route('admin.customers.store'), $data);

        $customer = Customer::query()->firstOrFail();
        $response->assertRedirectToRoute('admin.customers.show', $customer)
            ->assertSessionHas('success', 'Customer berhasil ditambahkan.');
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', $data);
        $this->get(route('admin.customers.show', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Customer berhasil ditambahkan.')->etc());
    }

    public function test_optional_fields_can_be_omitted_and_duplicate_name_and_email_are_allowed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.customers.store'), ['name' => 'Minimal Customer'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'name' => 'Minimal Customer', 'pic_name' => null, 'phone' => null, 'email' => null, 'billing_address' => null,
        ]);
        $this->post(route('admin.customers.store'), $this->customerData())->assertSessionHasNoErrors();
        $this->post(route('admin.customers.store'), $this->customerData())->assertSessionHasNoErrors();
        $this->assertSame(2, Customer::where('email', 'office@example.test')->count());
    }

    public function test_store_and_update_ignore_unvalidated_attributes(): void
    {
        $this->travelTo('2026-11-04 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $data = [...$this->customerData(), 'id' => 999999, 'created_at' => '2000-01-01', 'sites_count' => 10];

        $this->actingAs($admin)->post(route('admin.customers.store'), $data)->assertSessionHasNoErrors();

        $customer = Customer::query()->firstOrFail();
        $this->assertNotSame(999999, $customer->id);
        $this->assertSame('2026-11-04', $customer->created_at->toDateString());
        $this->patch(route('admin.customers.update', $customer), [...$data, 'name' => 'Updated Customer'])
            ->assertSessionHasNoErrors();
        $customer->refresh();
        $this->assertSame('Updated Customer', $customer->name);
        $this->assertSame('2026-11-04', $customer->created_at->toDateString());
        $this->assertDatabaseMissing('customers', ['id' => 999999]);
    }

    /**
     * @param  array<string, mixed>  $invalid
     */
    #[DataProvider('invalidCustomerData')]
    public function test_invalid_data_is_rejected_without_creating_or_changing_customers(string $method, array $invalid, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create($this->customerData());
        $url = $method === 'post' ? route('admin.customers.store') : route('admin.customers.update', $customer);

        $response = $this->actingAs($admin)->from(route('admin.customers.index'))
            ->{$method}($url, [...$this->customerData(), ...$invalid]);

        $response->assertRedirectToRoute('admin.customers.index')->assertSessionHasErrors([$field => $message]);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, ...$this->customerData()]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function invalidCustomerData(): array
    {
        $cases = [
            'required name' => [['name' => ''], 'name', 'The name field is required.'],
            'name type' => [['name' => ['invalid']], 'name', 'The name field must be a string.'],
            'name length' => [['name' => str_repeat('a', 151)], 'name', 'The name field must not be greater than 150 characters.'],
            'PIC length' => [['pic_name' => str_repeat('a', 151)], 'pic_name', 'The pic name field must not be greater than 150 characters.'],
            'phone length' => [['phone' => str_repeat('1', 31)], 'phone', 'The phone field must not be greater than 30 characters.'],
            'email length' => [['email' => str_repeat('a', 140).'@example.test'], 'email', 'The email field must not be greater than 150 characters.'],
            'invalid email' => [['email' => 'invalid'], 'email', 'The email field must be a valid email address.'],
            'address type' => [['billing_address' => ['invalid']], 'billing_address', 'The billing address field must be a string.'],
            'address byte limit' => [['billing_address' => str_repeat('é', 32768)], 'billing_address', 'Alamat penagihan maksimal 65.535 byte.'],
            'malformed name' => [['name' => "\xFF"], 'name', 'The name field must be encoded in UTF-8.'],
            'malformed address' => [['billing_address' => "\xFF"], 'billing_address', 'The billing address field must be encoded in UTF-8.'],
        ];
        $data = [];
        foreach (['post', 'patch'] as $method) {
            foreach ($cases as $label => [$invalid, $field, $message]) {
                $data[$method.' '.$label] = [$method, $invalid, $field, $message];
            }
        }

        return $data;
    }

    public function test_database_length_boundaries_and_unicode_are_accepted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = [
            'name' => str_repeat('é', 150), 'pic_name' => str_repeat('é', 150),
            'phone' => str_repeat('1', 30), 'email' => str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 24),
            'billing_address' => str_repeat('a', 65535),
        ];

        $this->actingAs($admin)->post(route('admin.customers.store'), $data)
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('customers', $data);
    }

    public function test_admin_updates_customer_and_can_clear_optional_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create($this->customerData());

        $this->actingAs($admin)->patch(route('admin.customers.update', $customer), [
            'name' => 'Updated Customer', 'pic_name' => '', 'phone' => '', 'email' => '', 'billing_address' => '',
        ])->assertRedirectToRoute('admin.customers.show', $customer)
            ->assertSessionHas('success', 'Customer berhasil diperbarui.');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id, 'name' => 'Updated Customer', 'pic_name' => null, 'phone' => null, 'email' => null, 'billing_address' => null,
        ]);
    }

    public function test_detail_returns_customer_fields_and_only_their_sites_with_bounded_queries(): void
    {
        $this->travelTo('2026-11-04 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create($this->customerData());
        for ($index = 1; $index <= 12; $index++) {
            $customer->sites()->create(['site_name' => sprintf('Site %02d', $index), 'address' => 'Bekasi']);
        }
        Customer::create(['name' => 'Unrelated Customer'])->sites()->create(['site_name' => 'Unrelated Site', 'address' => 'Jakarta']);
        $this->actingAs($admin);
        DB::enableQueryLog();
        try {
            $response = $this->get(route('admin.customers.show', $customer))->assertOk();
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertInertia(fn (Assert $page) => $page->component('admin/customers/show')
            ->where('customer.id', $customer->id)
            ->where('customer.name', 'PT Customer Testing')
            ->where('customer.pic_name', 'Budi Santoso')
            ->where('customer.phone', '+62 812 1234 5678')
            ->where('customer.email', 'office@example.test')
            ->where('customer.billing_address', "Jalan Testing 1\nBekasi")
            ->where('customer.created_at', '2026-11-04')
            ->where('customer.sites_count', 12)->has('customer.sites', 12)
            ->where('customer.sites.0.site_name', 'Site 01')
            ->where('links.edit', route('admin.customers.edit', $customer))
            ->where('links.destroy', route('admin.customers.destroy', $customer))->etc());
        $this->assertLessThanOrEqual(8, $queryCount);
    }

    public function test_admin_deletes_customer_without_dependencies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create($this->customerData());

        $this->actingAs($admin)->delete(route('admin.customers.destroy', $customer))
            ->assertRedirectToRoute('admin.customers.index')->assertSessionHas('success', 'Customer berhasil dihapus.');

        $this->assertModelMissing($customer);
    }

    public function test_site_and_operational_history_are_preserved_when_deletion_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create($this->customerData());
        $site = $customer->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
        $asset = $site->assets()->create(['asset_code' => 'ASSET-CRUD', 'category' => 'cctv']);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-CRUD', 'customer_site_id' => $site->id, 'created_by' => $admin->id,
            'title' => 'Maintenance', 'description' => 'Inspect equipment', 'service_type' => 'maintenance', 'category' => 'cctv',
        ]);

        $this->actingAs($admin)->from(route('admin.customers.show', $customer))
            ->delete(route('admin.customers.destroy', $customer))
            ->assertRedirectToRoute('admin.customers.show', $customer)
            ->assertSessionHasErrors(['customer' => 'Customer tidak dapat dihapus karena masih memiliki data terkait, seperti site atau invoice.']);

        foreach ([$customer, $site, $asset, $workOrder] as $record) {
            $this->assertModelExists($record);
        }
    }

    public function test_invoice_reference_prevents_deletion_even_without_sites(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $billedCustomer = Customer::create($this->customerData());
        $site = Customer::create(['name' => 'Site Owner'])->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-INVOICE', 'customer_site_id' => $site->id, 'created_by' => $admin->id,
            'title' => 'Maintenance', 'description' => 'Inspect equipment', 'service_type' => 'maintenance', 'category' => 'cctv',
        ]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CRUD', 'work_order_id' => $workOrder->id, 'customer_id' => $billedCustomer->id,
            'created_by' => $admin->id, 'bill_to_name' => $billedCustomer->name,
        ]);

        $this->actingAs($admin)->delete(route('admin.customers.destroy', $billedCustomer))
            ->assertSessionHasErrors('customer');

        $this->assertModelExists($billedCustomer);
        $this->assertModelExists($invoice);
        $this->assertModelExists($workOrder);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function crudEndpoints(): array
    {
        return [
            'create' => ['get', 'create'], 'store' => ['post', 'store'], 'show' => ['get', 'show'],
            'edit' => ['get', 'edit'], 'update' => ['patch', 'update'], 'destroy' => ['delete', 'destroy'],
        ];
    }

    #[DataProvider('crudEndpoints')]
    public function test_guest_is_redirected_without_mutating_customers(string $method, string $action): void
    {
        $customer = Customer::create($this->customerData());

        $this->call(strtoupper($method), route('admin.customers.'.$action, ['customer' => $customer->id]), $this->customerData())
            ->assertRedirectToRoute('login');

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, ...$this->customerData()]);
    }

    #[DataProvider('nonAdminEndpoints')]
    public function test_non_admin_receives_403_without_mutating_customers(string $method, string $action, ?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $customer = Customer::create($this->customerData());

        $this->actingAs($user)->call(strtoupper($method), route('admin.customers.'.$action, ['customer' => $customer->id]), $this->customerData())
            ->assertForbidden();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, ...$this->customerData()]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function nonAdminEndpoints(): array
    {
        $data = [];
        foreach (self::crudEndpoints() as $action => [$method, $route]) {
            foreach (['teknisi', 'koordinator', null] as $role) {
                $data[$action.' '.($role ?? 'no role')] = [$method, $route, $role];
            }
        }

        return $data;
    }

    #[DataProvider('missingCustomers')]
    public function test_missing_customer_returns_404(string $method, string $action): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->call(strtoupper($method), route('admin.customers.'.$action, ['customer' => 999999]), $this->customerData())
            ->assertNotFound();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function missingCustomers(): array
    {
        return [
            'show' => ['get', 'show'], 'edit' => ['get', 'edit'],
            'update' => ['patch', 'update'], 'destroy' => ['delete', 'destroy'],
        ];
    }

    /**
     * @return array{name: string, pic_name: string, phone: string, email: string, billing_address: string}
     */
    private function customerData(): array
    {
        return [
            'name' => 'PT Customer Testing', 'pic_name' => 'Budi Santoso', 'phone' => '+62 812 1234 5678',
            'email' => 'office@example.test', 'billing_address' => "Jalan Testing 1\nBekasi",
        ];
    }
}
