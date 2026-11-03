<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_customer_page_with_site_counts_and_search_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->createCustomer();
        $customer->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
        $customer->sites()->create(['site_name' => 'Warehouse', 'address' => 'Jakarta']);

        $response = $this->actingAs($admin)->get(route('admin.customers.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/customers/index')
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $customer->id)
            ->where('customers.data.0.sites_count', 2)
            ->where('customers.per_page', 10)
            ->where('filters.search', '')
            ->where('links.index', route('admin.customers.index'))
            ->etc());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.customers.index'))->assertRedirectToRoute('login');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_receives_403(?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('admin.customers.index'))->assertForbidden();
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'technician' => ['teknisi'],
            'coordinator' => ['koordinator'],
            'no role' => [null],
        ];
    }

    #[DataProvider('searchableFields')]
    public function test_search_returns_only_customers_matching_the_selected_field(string $field, string $value, string $search): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $match = $this->createCustomer([$field => $value]);
        $this->createCustomer(['phone' => $search, 'billing_address' => $search]);

        $response = $this->actingAs($admin)->get(route('admin.customers.index', ['search' => $search]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.total', 1)
            ->where('customers.data.0.id', $match->id)
            ->where('customers.data.0.sites_count', 0)
            ->where('filters.search', $search)
            ->etc());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function searchableFields(): array
    {
        return [
            'company name' => ['name', 'PT Nusantara Teknik', 'Nusantara'],
            'PIC name' => ['pic_name', 'Budi Santoso', 'Santoso'],
            'email' => ['email', 'sales@nusantara.test', 'sales@'],
        ];
    }

    /**
     * @param  array{search?: ?string}  $filters
     */
    #[DataProvider('emptySearches')]
    public function test_empty_search_returns_all_customers_in_newest_first_order(array $filters): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->createCustomer(['name' => 'First Customer']);
        $second = $this->createCustomer(['name' => 'Second Customer']);

        $response = $this->actingAs($admin)->get(route('admin.customers.index', $filters));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 2)
            ->where('customers.total', 2)
            ->where('customers.data.0.id', $second->id)
            ->where('customers.data.1.id', $first->id)
            ->where('filters.search', '')
            ->etc());
    }

    /**
     * @return array<string, array{0: array{search?: ?string}}>
     */
    public static function emptySearches(): array
    {
        return [
            'missing' => [[]],
            'null' => [['search' => null]],
            'empty' => [['search' => '']],
            'whitespace' => [['search' => '   ']],
        ];
    }

    public function test_search_is_trimmed_before_matching_and_returning_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->createCustomer(['name' => "PT O'Reilly Services"]);

        $response = $this->actingAs($admin)->get(route('admin.customers.index', ['search' => "  O'Reilly  "]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $customer->id)
            ->where('filters.search', "O'Reilly")
            ->etc());
    }

    public function test_search_accepts_100_characters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $search = str_repeat('a', 100);
        $customer = $this->createCustomer(['name' => $search]);

        $response = $this->actingAs($admin)->get(route('admin.customers.index', ['search' => $search]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $customer->id)
            ->where('filters.search', $search)
            ->etc());
    }

    /**
     * @param  array<int, string>|string  $search
     */
    #[DataProvider('invalidSearches')]
    public function test_invalid_search_redirects_with_a_validation_error(array|string $search, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->from(route('admin.customers.index'))
            ->get(route('admin.customers.index', ['search' => $search]));

        $response->assertRedirectToRoute('admin.customers.index');
        $this->get(route('admin.customers.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('errors.search', $message)->etc());
    }

    /**
     * @return array<string, array{0: array<int, string>|string, 1: string}>
     */
    public static function invalidSearches(): array
    {
        return [
            'array' => [['invalid'], 'The search field must be a string.'],
            'too long' => [str_repeat('a', 101), 'The search field must not be greater than 100 characters.'],
        ];
    }

    #[DataProvider('unmatchedSearches')]
    public function test_unmatched_search_returns_an_empty_page_with_the_filter_retained(string $search): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createCustomer();

        $response = $this->actingAs($admin)->get(route('admin.customers.index', ['search' => $search]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 0)
            ->where('customers.total', 0)
            ->where('filters.search', $search)
            ->where('customers.next_page_url', null)
            ->where('customers.prev_page_url', null)
            ->etc());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unmatchedSearches(): array
    {
        return [
            'no match' => ['Missing Customer'],
            'SQL-like text is a bound value' => ["' OR 1=1 --"],
        ];
    }

    public function test_pagination_preserves_search_and_returns_only_matching_customers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        for ($index = 1; $index <= 12; $index++) {
            $this->createCustomer(['name' => 'Matching Customer '.$index]);
        }
        $this->createCustomer();

        $response = $this->actingAs($admin)->get(route('admin.customers.index', ['search' => 'Matching Customer']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 10)
            ->where('customers.total', 12)
            ->where('customers.current_page', 1)
            ->where('customers.last_page', 2)
            ->etc());
        $next = $response->inertiaProps('customers.next_page_url');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'Matching Customer', 'page' => '2'], $query);

        $secondPage = $this->get($next)->assertOk();
        $secondPage->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 2)
            ->where('customers.total', 12)
            ->where('customers.current_page', 2)
            ->where('customers.next_page_url', null)
            ->where('filters.search', 'Matching Customer')
            ->etc());
        $previous = $secondPage->inertiaProps('customers.prev_page_url');
        parse_str(parse_url($previous, PHP_URL_QUERY), $previousQuery);
        $this->assertSame(['search' => 'Matching Customer', 'page' => '1'], $previousQuery);
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private function createCustomer(array $attributes = []): Customer
    {
        return Customer::create([
            'name' => 'PT Customer Default',
            'pic_name' => 'PIC Default',
            'email' => 'default@example.test',
            ...$attributes,
        ]);
    }
}
