<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkOrderCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_opens_form_with_minimal_site_and_asset_options_and_active_navigation(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $asset = $this->asset($site);
        $otherSite = $this->site('PT Other Customer');

        $response = $this->actingAs($user)->get(route('work-orders.create'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('work-orders/create')
            ->where('sites', [
                ['id' => $site->id, 'site_name' => 'Office', 'customer_name' => 'PT Testing',
                    'assets' => [['id' => $asset->id, 'asset_code' => $asset->asset_code, 'category' => 'cctv']]],
                ['id' => $otherSite->id, 'site_name' => 'Office', 'customer_name' => 'PT Other Customer', 'assets' => []],
            ])
            ->where('serviceTypes', ['install' => 'Instalasi', 'maintenance' => 'Maintenance'])
            ->where('priorities', ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'])
            ->where('scheduleTimezone', 'UTC')
            ->where('links', ['index' => route('work-orders.index'), 'store' => route('work-orders.store')])
            ->missing('users')->missing('customers')->etc());
        $navigation = collect($response->inertiaProps('navigation'))->firstWhere('id', 'work-orders');
        $this->assertTrue($navigation['active']);
        $this->assertTrue($navigation['inertia']);
        $this->assertSame(route('work-orders.index'), $navigation['href']);
    }

    public function test_form_supports_an_empty_database(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);

        $this->actingAs($user)->get(route('work-orders.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('work-orders/create')->where('sites', [])->etc());
    }

    #[DataProvider('endpoints')]
    public function test_guest_is_redirected_to_login(string $method, string $action): void
    {
        $this->call($method, route('work-orders.'.$action))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('work_orders', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return ['form' => ['GET', 'create'], 'submission' => ['POST', 'store']];
    }

    #[DataProvider('forbiddenEndpoints')]
    public function test_other_roles_cannot_open_or_submit_creation(string $method, string $action, ?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $data = $this->data($this->site());

        $this->actingAs($user)->call($method, route('work-orders.'.$action), $data)->assertForbidden();

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function forbiddenEndpoints(): array
    {
        $cases = [];
        foreach (self::endpoints() as $name => [$method, $action]) {
            foreach (['admin', 'teknisi', 'finance', 'manager', null, 'Koordinator'] as $role) {
                $cases[$name.' '.($role ?? 'no role')] = [$method, $action, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('blockedEndpoints')]
    public function test_inactive_or_unapproved_coordinators_are_logged_out(string $method, string $action, bool $active, string $approval): void
    {
        $user = User::factory()->create(['role' => 'koordinator', 'is_active' => $active, 'approval_status' => $approval]);
        $data = $this->data($this->site());

        $this->actingAs($user)->call($method, route('work-orders.'.$action), $data)
            ->assertRedirectToRoute('login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    /** @return array<string, array{string, string, bool, string}> */
    public static function blockedEndpoints(): array
    {
        $cases = [];
        foreach (self::endpoints() as $name => [$method, $action]) {
            foreach (['inactive' => [false, 'approved'], 'pending' => [true, 'pending'], 'rejected' => [true, 'rejected']] as $state => [$active, $approval]) {
                $cases[$name.' '.$state] = [$method, $action, $active, $approval];
            }
        }

        return $cases;
    }

    public function test_creation_saves_correct_fields_and_assets_then_redirects_to_the_listing_with_success(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $assets = [$this->asset($site), $this->asset($site)];
        $data = [...$this->data($site), 'scheduled_at' => '2026-11-15T09:30', 'asset_ids' => [$assets[1]->id, $assets[0]->id]];

        $this->actingAs($user)->post(route('work-orders.store'), $data)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index')
            ->assertSessionHas('success', 'Work Order berhasil ditambahkan.');

        $workOrder = WorkOrder::query()->sole();
        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id, 'customer_site_id' => $site->id, 'created_by' => $user->id,
            'title' => 'Inspect cameras', 'description' => 'Camera offline', 'service_type' => 'maintenance',
            'category' => 'cctv', 'priority' => 'high', 'status' => 'pending',
            'scheduled_at' => '2026-11-15 09:30:00', 'verified_by' => null, 'completed_at' => null,
        ]);
        foreach ($assets as $asset) {
            $this->assertDatabaseHas('work_order_assets', ['work_order_id' => $workOrder->id, 'asset_id' => $asset->id]);
        }
        $this->assertDatabaseCount('work_order_assets', 2);
        $this->assertDatabaseCount('work_order_technicians', 0);
        $this->assertDatabaseCount('work_order_updates', 0);
        $this->get(route('work-orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Work Order berhasil ditambahkan.')
            ->where('workOrders.data.0.id', $workOrder->id)->where('workOrders.data.0.scheduled_at', '2026-11-15')
            ->where('workOrders.data.0.technicians', [])->etc());
    }

    #[DataProvider('optionalData')]
    public function test_installation_can_be_created_without_assets_or_schedule(array $optional): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $data = [...$this->data($this->site()), 'service_type' => 'install', ...$optional];

        $this->actingAs($user)->post(route('work-orders.store'), $data)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->assertDatabaseHas('work_orders', ['service_type' => 'install', 'scheduled_at' => null]);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function optionalData(): array
    {
        return ['omitted' => [[]], 'empty' => [['asset_ids' => [], 'scheduled_at' => '']], 'null schedule' => [['scheduled_at' => null]]];
    }

    public function test_server_managed_fields_cannot_be_overridden_and_no_assignment_or_history_is_created(): void
    {
        $this->travelTo('2026-11-10 09:00:00');
        $user = User::factory()->create(['role' => 'koordinator']);
        $other = User::factory()->create(['role' => 'teknisi']);
        $data = [...$this->data($this->site()), 'wo_number' => 'FORGED', 'created_by' => $other->id,
            'status' => 'closed', 'verified_by' => $other->id, 'completed_at' => '2026-01-01',
            'id' => 999999, 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01',
            'technician_ids' => [$other->id], 'technicians' => [$other->id], 'updates' => [['to_status' => 'closed']]];

        $this->actingAs($user)->post(route('work-orders.store'), $data)->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $workOrder = WorkOrder::query()->sole();
        $this->assertSame($user->id, $workOrder->created_by);
        $this->assertSame('pending', $workOrder->status);
        $this->assertNull($workOrder->verified_by);
        $this->assertNull($workOrder->completed_at);
        $this->assertNotSame('FORGED', $workOrder->wo_number);
        $this->assertNotSame(999999, $workOrder->id);
        $this->assertSame('2026-11-10 09:00:00', $workOrder->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 09:00:00', $workOrder->updated_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('work_order_technicians', 0);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_consecutive_creations_generate_unique_ulid_numbers_within_schema_length(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['role' => 'koordinator']);
        $data = $this->data($this->site());
        $this->actingAs($user);

        for ($index = 0; $index < 3; $index++) {
            $this->post(route('work-orders.store'), $data)->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');
        }

        $numbers = WorkOrder::query()->pluck('wo_number')->all();
        $this->assertCount(3, array_unique($numbers));
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^WO-[0-9A-HJKMNP-TV-Z]{26}$/', $number);
            $this->assertLessThanOrEqual(50, strlen($number));
        }
    }

    public function test_required_fields_return_validation_errors_without_partial_records(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);

        $this->actingAs($user)->from(route('work-orders.create'))->post(route('work-orders.store'), [])
            ->assertRedirectToRoute('work-orders.create')->assertSessionHasErrors([
                'customer_site_id', 'title', 'description', 'service_type', 'category', 'priority',
            ]);

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    public function test_number_collision_is_rejected_by_the_database_without_partial_records(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $data = $this->data($this->site());
        $number = Str::ulid();
        Str::createUlidsUsing(fn () => $number);
        Exceptions::fake();
        $this->actingAs($user);

        try {
            $this->post(route('work-orders.store'), $data)->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');
            $this->post(route('work-orders.store'), $data)->assertInternalServerError();
        } finally {
            Str::createUlidsNormally();
        }

        Exceptions::assertReported(UniqueConstraintViolationException::class);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    #[DataProvider('invalidData')]
    public function test_invalid_data_is_rejected_without_partial_records(array $invalid, string $field): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $data = [...$this->data($this->site()), ...$invalid];

        $this->actingAs($user)->from(route('work-orders.create'))->post(route('work-orders.store'), $data)
            ->assertRedirectToRoute('work-orders.create')->assertSessionHasErrors($field);

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidData(): array
    {
        $cases = [
            'missing site' => [['customer_site_id' => 999999], 'customer_site_id'],
            'site string' => [['customer_site_id' => 'invalid'], 'customer_site_id'],
            'site array' => [['customer_site_id' => [1]], 'customer_site_id'],
            'invalid service' => [['service_type' => 'repair'], 'service_type'],
            'service array' => [['service_type' => ['install']], 'service_type'],
            'invalid priority' => [['priority' => 'urgent'], 'priority'],
            'priority array' => [['priority' => ['high']], 'priority'],
            'invalid calendar date' => [['scheduled_at' => '2026-02-30T10:30'], 'scheduled_at'],
            'invalid date format' => [['scheduled_at' => '15/11/2026 09:30'], 'scheduled_at'],
            'date without time' => [['scheduled_at' => '2026-11-15'], 'scheduled_at'],
            'timezone suffix' => [['scheduled_at' => '2026-11-15T09:30+07:00'], 'scheduled_at'],
            'date array' => [['scheduled_at' => ['2026-11-15T09:30']], 'scheduled_at'],
            'oversized text bytes' => [['description' => str_repeat('設', 21846)], 'description'],
            'asset scalar' => [['asset_ids' => 1], 'asset_ids'],
            'asset null' => [['asset_ids' => null], 'asset_ids'],
            'asset associative array' => [['asset_ids' => ['device' => 999999]], 'asset_ids'],
            'nonexistent asset' => [['asset_ids' => [999999]], 'asset_ids.0'],
            'invalid asset' => [['asset_ids' => ['invalid']], 'asset_ids.0'],
            'asset nested array' => [['asset_ids' => [[1]]], 'asset_ids.0'],
            'asset null entry' => [['asset_ids' => [null]], 'asset_ids.0'],
        ];
        foreach (['title' => 150, 'category' => 50] as $field => $limit) {
            $cases[$field.' length'] = [[$field => str_repeat('設', $limit + 1)], $field];
        }
        foreach (['title', 'category', 'description'] as $field) {
            $cases[$field.' type'] = [[$field => ['invalid']], $field];
            $cases[$field.' encoding'] = [[$field => "\xFF"], $field];
        }

        return $cases;
    }

    #[DataProvider('otherPriorities')]
    public function test_schema_length_boundaries_and_free_text_category_are_accepted(string $priority): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $data = [...$this->data($this->site()), 'title' => str_repeat('設', 150),
            'category' => str_repeat('é', 50), 'description' => str_repeat('a', 65535), 'priority' => $priority];

        $this->actingAs($user)->post(route('work-orders.store'), $data)->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->assertDatabaseHas('work_orders', $data);
    }

    /** @return array<string, array{string}> */
    public static function otherPriorities(): array
    {
        return ['low' => ['low'], 'medium' => ['medium']];
    }

    public function test_cross_site_assets_are_rejected_even_when_the_customer_is_the_same(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $otherSite = $site->customer->sites()->create(['site_name' => 'Warehouse', 'address' => 'Jakarta']);
        $sameSite = $this->asset($site);
        $otherSiteAsset = $this->asset($otherSite);
        $otherCustomerAsset = $this->asset($this->site('Other Customer'));
        $data = [...$this->data($site), 'asset_ids' => [$sameSite->id, $otherSiteAsset->id, $otherCustomerAsset->id]];

        $this->actingAs($user)->post(route('work-orders.store'), $data)->assertSessionHasErrors([
            'asset_ids' => 'Pilih asset yang masih tersedia di Customer Site terpilih.',
        ]);

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    public function test_duplicate_asset_ids_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $asset = $this->asset($site);

        $this->actingAs($user)->post(route('work-orders.store'), [
            ...$this->data($site), 'asset_ids' => [$asset->id, (string) $asset->id],
        ])->assertSessionHasErrors(['asset_ids.0', 'asset_ids.1']);

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    public function test_asset_site_is_rechecked_after_initial_validation(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $otherSite = $this->site();
        $asset = $this->asset($site);
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($asset, $otherSite, &$changed): void {
            if (! $changed && str_contains($sql, 'from `assets`') && str_contains($sql, 'for update')) {
                $changed = true;
                DB::table('assets')->where('id', $asset->id)->update(['customer_site_id' => $otherSite->id]);
            }
        });

        $this->actingAs($user)->post(route('work-orders.store'), [...$this->data($site), 'asset_ids' => [$asset->id]])
            ->assertSessionHasErrors('asset_ids');

        $this->assertTrue($changed);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    public function test_association_failure_rolls_back_the_work_order(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $asset = $this->asset($site);
        $reachedAttachment = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$reachedAttachment): void {
            if (str_starts_with($sql, 'insert into `work_order_assets`')) {
                $reachedAttachment = WorkOrder::query()->exists();
                throw new RuntimeException('Simulated association failure.');
            }
        });
        Exceptions::fake();

        $this->actingAs($user)->post(route('work-orders.store'), [...$this->data($site), 'asset_ids' => [$asset->id]])
            ->assertInternalServerError();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertTrue($reachedAttachment);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
        $this->assertModelExists($asset);
    }

    public function test_deleted_asset_is_rejected_after_initial_validation(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $asset = $this->asset($site);
        $deleted = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($asset, &$deleted): void {
            if (! $deleted && str_contains($sql, 'from `assets`') && str_contains($sql, 'for update')) {
                $deleted = true;
                DB::table('assets')->where('id', $asset->id)->delete();
            }
        });

        $this->actingAs($user)->post(route('work-orders.store'), [...$this->data($site), 'asset_ids' => [$asset->id]])
            ->assertSessionHasErrors('asset_ids');

        $this->assertTrue($deleted);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    public function test_deleted_site_is_rejected_after_initial_validation(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $deleted = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($site, &$deleted): void {
            if (! $deleted && str_contains($sql, 'from `customer_sites`') && str_contains($sql, 'lock in share mode')) {
                $deleted = true;
                DB::table('customer_sites')->where('id', $site->id)->delete();
            }
        });

        $this->actingAs($user)->post(route('work-orders.store'), $this->data($site))
            ->assertSessionHasErrors(['customer_site_id' => 'Customer Site tidak tersedia.']);

        $this->assertTrue($deleted);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
    }

    public function test_attached_asset_cannot_be_moved_to_another_site_after_creation(): void
    {
        $user = User::factory()->create(['role' => 'koordinator']);
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->site();
        $otherSite = $this->site();
        $asset = $this->asset($site);
        $this->actingAs($user)->post(route('work-orders.store'), [...$this->data($site), 'asset_ids' => [$asset->id]])
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->actingAs($admin)->patch(route('admin.assets.update', $asset), [
            'customer_site_id' => $otherSite->id, 'asset_code' => $asset->asset_code, 'category' => 'cctv',
        ])->assertSessionHasErrors('customer_site_id');

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'customer_site_id' => $site->id]);
        $this->assertDatabaseHas('work_order_assets', ['asset_id' => $asset->id, 'work_order_id' => WorkOrder::query()->sole()->id]);
    }

    #[DataProvider('listingRoles')]
    public function test_only_coordinators_receive_a_usable_creation_link(string $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('work-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.create', $allowed ? route('work-orders.create') : null)->etc());
    }

    /** @return array<string, array{string, bool}> */
    public static function listingRoles(): array
    {
        return ['admin' => ['admin', false], 'coordinator' => ['koordinator', true], 'technician' => ['teknisi', false]];
    }

    private function site(string $customerName = 'PT Testing'): CustomerSite
    {
        return Customer::create(['name' => $customerName])->sites()->create(['site_name' => 'Office', 'address' => 'Jakarta']);
    }

    private function asset(CustomerSite $site): Asset
    {
        return $site->assets()->create(['asset_code' => 'AST-'.fake()->uuid(), 'category' => 'cctv', 'serial_number' => 'Private serial']);
    }

    /** @return array{customer_site_id: int, title: string, description: string, service_type: string, category: string, priority: string} */
    private function data(CustomerSite $site): array
    {
        return ['customer_site_id' => $site->id, 'title' => 'Inspect cameras', 'description' => 'Camera offline',
            'service_type' => 'maintenance', 'category' => 'cctv', 'priority' => 'high'];
    }
}
