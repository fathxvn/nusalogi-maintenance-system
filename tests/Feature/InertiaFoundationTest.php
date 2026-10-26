<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InertiaFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_fortify_login_before_accessing_inertia_page(): void
    {
        $this->get(route('migration.test'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_active_user_receives_inertia_root_view_with_only_public_user_summary(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('migration.test'))
            ->assertOk()
            ->assertViewIs('app')
            ->assertSee('id="app"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('migration-test')
                ->where('user', ['id' => $user->id, 'name' => $user->name])
                ->etc());
    }

    public function test_inertia_navigation_returns_the_expected_json_protocol(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $initialPage = $this->actingAs($user)->get(route('migration.test'))->assertOk()->inertiaPage();

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $initialPage['version'],
        ])->get(route('migration.test'))
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'migration-test')
            ->assertJsonPath('props.user', ['id' => $user->id, 'name' => $user->name])
            ->assertJsonPath('url', '/migration-test');
    }

    public function test_inactive_user_session_is_terminated_before_inertia_page_is_rendered(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get(route('migration.test'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_fortify_login_restores_intended_inertia_page_using_the_same_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->get(route('migration.test'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('migration.test'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('migration.test'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('migration-test')
            ->where('user.id', $user->id)
            ->etc());
    }

    #[DataProvider('legacyAuthenticationViews')]
    public function test_legacy_fortify_pages_still_render_blade(string $routeName, array $parameters, string $view): void
    {
        $this->get(route($routeName, $parameters))->assertOk()->assertViewIs($view);
    }

    public static function legacyAuthenticationViews(): array
    {
        return [
            'login' => ['login', [], 'auth.login'],
            'forgot password' => ['password.request', [], 'auth.forgot-password'],
            'reset password' => ['password.reset', ['token' => 'test-placeholder'], 'auth.reset-password'],
        ];
    }

    public function test_authenticated_dashboard_renders_inertia(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard')->etc());
    }
}
