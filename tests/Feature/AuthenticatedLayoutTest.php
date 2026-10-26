<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticatedLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_shared_auth_contains_only_the_required_user_fields(?string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);

        $this->actingAs($user)->get(route('migration.test'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('migration-test')
                ->where('auth.user', ['id' => $user->id, 'name' => $user->name, 'role' => $role])
                ->missing('auth.password')
                ->missing('auth.token')
                ->etc());
    }

    public static function roles(): array
    {
        return [
            'admin' => ['admin'],
            'coordinator' => ['koordinator'],
            'technician' => ['teknisi'],
            'no role' => [null],
        ];
    }

    public function test_admin_navigation_points_to_migrated_inertia_pages(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $response = $this->actingAs($user)->get(route('migration.test'))->assertOk();
        $items = collect($response->inertiaProps('navigation'))->keyBy('id');

        $this->assertSame(route('dashboard'), $items['dashboard']['href']);
        $this->assertTrue($items['dashboard']['inertia']);
        $this->assertSame(route('admin.users.index'), $items['users']['href']);
        $this->assertTrue($items['users']['inertia']);

        $this->get($items['dashboard']['href'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard')->etc());
        $this->get($items['users']['href'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/users/index')->etc());
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_navigation_omits_user_management_and_server_still_forbids_access(?string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $response = $this->actingAs($user)->get(route('migration.test'))->assertOk();
        $items = collect($response->inertiaProps('navigation'))->keyBy('id');

        $this->assertFalse($items->has('users'));
        $this->assertSame(route('dashboard'), $items['dashboard']['href']);
        $this->get(route('admin.users.index'))->assertForbidden();
    }

    public static function nonAdminRoles(): array
    {
        return ['coordinator' => ['koordinator'], 'technician' => ['teknisi'], 'no role' => [null]];
    }

    public function test_unimplemented_modules_have_no_links_and_preview_is_the_active_inertia_destination(): void
    {
        $user = User::factory()->create(['role' => 'teknisi', 'is_active' => true]);
        $response = $this->actingAs($user)->get(route('migration.test'))->assertOk();
        $items = collect($response->inertiaProps('navigation'))->keyBy('id');

        foreach (['work-orders', 'customers', 'assets', 'invoices'] as $id) {
            $this->assertNull($items[$id]['href']);
            $this->assertFalse($items[$id]['active']);
        }

        $this->assertSame(route('migration.test'), $items['preview']['href']);
        $this->assertTrue($items['preview']['inertia']);
        $this->assertSame(['preview'], $items->filter(fn (array $item): bool => $item['active'])->keys()->all());
    }

    public function test_layout_exposes_a_native_post_logout_form_and_fortify_ends_the_session(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $response = $this->actingAs($user)->get(route('migration.test'))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form[@id="nusalogi-logout-form"]');

        $this->assertCount(1, $forms);
        $form = $forms->item(0);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(route('logout'), $form->getAttribute('action'));
        $this->assertCount(1, $xpath->query('input[@type="hidden" and @name="_token" and string-length(@value) > 0]', $form));

        $this->post($form->getAttribute('action'))->assertRedirect('/');
        $this->assertGuest();
        $this->get(route('migration.test'))->assertRedirect(route('login'));
    }
}
