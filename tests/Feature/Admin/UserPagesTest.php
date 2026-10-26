<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_real_users_and_excludes_sensitive_attributes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        User::factory()->count(12)->create(['role' => 'teknisi', 'is_active' => true]);
        $response = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('admin/users/index')
            ->has('users.data', 10)->where('users.total', 13)
            ->missing('users.data.0.password')->missing('users.data.0.remember_token')->etc());
        $keys = array_keys($response->inertiaProps('users.data.0'));
        sort($keys);
        $this->assertSame(['created_at', 'email', 'id', 'is_active', 'is_self', 'links', 'name', 'role'], $keys);
    }

    public function test_search_role_and_status_filters_are_combined_without_or_leakage(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $match = User::factory()->create(['name' => 'Search Target', 'role' => 'teknisi', 'is_active' => false]);
        User::factory()->create(['name' => 'Search Target', 'role' => 'admin', 'is_active' => false]);
        User::factory()->create(['email' => 'search@example.test', 'role' => 'teknisi', 'is_active' => true]);

        $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Search', 'role' => 'teknisi', 'status' => 'inactive']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.id', $match->id)->etc());
    }

    public function test_email_search_and_query_parameters_are_preserved_in_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        User::factory()->count(12)->create(['name' => 'Matching', 'role' => 'teknisi', 'is_active' => true]);
        $response = $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Matching', 'role' => 'teknisi', 'status' => 'active']))->assertOk();
        $next = $response->inertiaProps('users.next_page_url');
        $this->assertStringContainsString('search=Matching', $next);
        $this->assertStringContainsString('role=teknisi', $next);
        $this->assertStringContainsString('status=active', $next);
        $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page->where('users.current_page', 2)->has('users.data', 2)->etc());

        $this->get(route('admin.users.index', ['search' => $admin->email]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.id', $admin->id)->etc());
    }

    public function test_create_and_edit_render_safe_inertia_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'teknisi', 'is_active' => true]);
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/users/create')->where('roles', User::ROLES)->etc());
        $this->get(route('admin.users.edit', $target))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/edit')->where('user', $target->only(['id', 'name', 'email', 'role', 'is_active']))
            ->where('roles', User::ROLES)->etc());
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(array $filters, string $field): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('admin.users.index', $filters))->assertSessionHasErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            'role' => [['role' => 'superadmin'], 'role'],
            'status' => [['status' => 'other'], 'status'],
            'long search' => [['search' => str_repeat('a', 101)], 'search'],
            'array search' => [['search' => ['bad']], 'search'],
        ];
    }

    public function test_success_feedback_survives_redirect_without_exposing_passwords(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'teknisi', 'is_active' => true]);
        $this->actingAs($admin)->patch(route('admin.users.update-role', $target), ['role' => 'koordinator'])->assertRedirect(route('admin.users.index'));
        $this->get(route('admin.users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Role pengguna berhasil diperbarui.')->etc());
        $this->assertSame('koordinator', $target->fresh()->role);
    }
}
