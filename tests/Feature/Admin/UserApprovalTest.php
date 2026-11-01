<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserApprovalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function initialRoles(): array
    {
        return ['technician' => ['teknisi'], 'coordinator' => ['koordinator']];
    }

    #[DataProvider('initialRoles')]
    public function test_admin_can_approve_google_registration_with_selected_role(string $role): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->pendingUser();

        $this->actingAs($admin)->patch(route('admin.users.approve', $user), [
            'role' => $role,
            'approval_status' => 'rejected',
            'google_id' => 'tampered',
        ])->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('approved', $user->approval_status);
        $this->assertSame($role, $user->role);
        $this->assertTrue($user->isActive());
        $this->assertSame('google-pending', $user->google_id);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_approved_account_can_login_with_selected_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->pendingUser();
        $this->actingAs($admin)->patch(route('admin.users.approve', $user), ['role' => 'teknisi'])
            ->assertSessionHasNoErrors();
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('teknisi', $user->fresh()->role);
    }

    public function test_rejection_preserves_inactive_account_and_blocks_password_and_google_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->pendingUser();

        $this->actingAs($admin)->patch(route('admin.users.reject', $user), ['role' => 'admin', 'is_active' => true])
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors()->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('rejected', $user->approval_status);
        $this->assertFalse($user->isActive());
        $this->assertNull($user->role);
        $this->assertSame('google-pending', $user->google_id);
        $this->assertModelExists($user);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        Socialite::fake('google', (new GoogleUser)->map([
            'id' => 'google-pending', 'email' => $user->email,
        ])->setRaw(['email_verified' => true]));
        $this->get(route('auth.google.callback', ['code' => 'code']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** @return array<string, array{?string, string}> */
    public static function unauthorizedReviewers(): array
    {
        return [
            'guest approve' => [null, 'approve'], 'guest reject' => [null, 'reject'],
            'technician approve' => ['teknisi', 'approve'], 'technician reject' => ['teknisi', 'reject'],
            'coordinator approve' => ['koordinator', 'approve'], 'coordinator reject' => ['koordinator', 'reject'],
        ];
    }

    #[DataProvider('unauthorizedReviewers')]
    public function test_non_admin_cannot_review_registration(?string $role, string $decision): void
    {
        $user = $this->pendingUser();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }

        $response = $this->patch(route('admin.users.'.$decision, $user), ['role' => 'teknisi']);

        if ($role === null) {
            $response->assertRedirect(route('login'));
        } else {
            $response->assertForbidden();
        }
        $this->assertSame('pending', $user->fresh()->approval_status);
        $this->assertFalse($user->fresh()->isActive());
        $this->assertNull($user->fresh()->role);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidRoles(): array
    {
        return ['missing' => [null], 'admin' => ['admin'], 'invalid' => ['superadmin'], 'array' => [['teknisi']]];
    }

    #[DataProvider('invalidRoles')]
    public function test_approval_rejects_invalid_or_admin_initial_role(mixed $role): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->pendingUser();

        $this->actingAs($admin)->patch(route('admin.users.approve', $user), ['role' => $role])
            ->assertSessionHasErrors('role');

        $this->assertSame('pending', $user->fresh()->approval_status);
        $this->assertFalse($user->fresh()->isActive());
        $this->assertNull($user->fresh()->role);
    }

    /** @return array<string, array{string}> */
    public static function unapprovedStates(): array
    {
        return ['pending' => ['pending'], 'rejected' => ['rejected']];
    }

    #[DataProvider('unapprovedStates')]
    public function test_activation_and_role_editing_cannot_bypass_approval(string $status): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->pendingUser();
        $user->approval_status = $status;
        $user->save();

        $this->actingAs($admin)->patch(route('admin.users.update-status', $user), ['is_active' => true])
            ->assertSessionHasErrors('is_active');
        $this->patch(route('admin.users.update-role', $user), ['role' => 'admin'])
            ->assertSessionHasErrors('role');

        $this->assertSame($status, $user->fresh()->approval_status);
        $this->assertFalse($user->fresh()->isActive());
        $this->assertNull($user->fresh()->role);
    }

    /** @return array<string, array{string, string}> */
    public static function repeatedDecisions(): array
    {
        return [
            'approve twice' => ['approve', 'approve'], 'approve then reject' => ['approve', 'reject'],
            'reject then approve' => ['reject', 'approve'], 'reject twice' => ['reject', 'reject'],
        ];
    }

    #[DataProvider('repeatedDecisions')]
    public function test_first_review_decision_cannot_be_overwritten(string $first, string $second): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->pendingUser();
        $this->actingAs($admin)->patch(route('admin.users.'.$first, $user), ['role' => 'teknisi'])
            ->assertSessionHasNoErrors();
        $attributes = $user->fresh()->getAttributes();

        $this->patch(route('admin.users.'.$second, $user), ['role' => 'koordinator'])
            ->assertSessionHasErrors('approval_status');

        $this->assertSame($attributes, $user->fresh()->getAttributes());
    }

    #[DataProvider('repeatedDecisions')]
    public function test_stale_route_model_cannot_overwrite_a_competing_review(string $first, string $second): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->pendingUser();
        $staleUser = clone $user;
        $this->actingAs($admin)->patch(route('admin.users.'.$first, $user), ['role' => 'teknisi'])
            ->assertSessionHasNoErrors();
        $attributes = $user->fresh()->getAttributes();
        Route::bind('user', fn (string $id): User => $staleUser);

        $this->patch(route('admin.users.'.$second, $user), ['role' => 'koordinator'])
            ->assertSessionHasErrors('approval_status');

        $this->assertSame($attributes, $user->fresh()->getAttributes());
    }

    public function test_review_actions_cannot_modify_self_or_local_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $local = User::factory()->create(['approval_status' => 'pending', 'is_active' => false]);

        $this->actingAs($admin)->patch(route('admin.users.approve', $admin), ['role' => 'teknisi'])
            ->assertSessionHasErrors('approval_status');
        $this->patch(route('admin.users.reject', $admin))->assertSessionHasErrors('approval_status');
        $this->patch(route('admin.users.approve', $local), ['role' => 'teknisi'])->assertSessionHasErrors('approval_status');
        $this->patch(route('admin.users.reject', $local))->assertSessionHasErrors('approval_status');

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->isActive());
        $this->assertSame('pending', $local->fresh()->approval_status);
    }

    public function test_approval_filter_combines_with_search_status_and_pagination_without_sensitive_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(12)->create([
            'name' => 'Google Pending', 'google_id' => null, 'role' => null,
            'approval_status' => 'pending', 'is_active' => false,
        ]);
        $google = $this->pendingUser();
        $google->name = 'Google Pending';
        $google->save();
        User::factory()->create(['name' => 'Google Approved', 'approval_status' => 'approved', 'is_active' => false]);
        User::factory()->create(['name' => 'Google Rejected', 'approval_status' => 'rejected', 'is_active' => false]);
        User::factory()->create(['name' => 'Other Pending', 'approval_status' => 'pending', 'is_active' => false]);

        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'approval_status' => 'pending', 'search' => 'Google', 'status' => 'inactive',
        ]))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('users.data', 10)
            ->where('users.total', 13)->where('users.data.0.id', $google->id)
            ->where('users.data.0.can_review', true)->where('users.data.1.can_review', false)
            ->where('approvalRoles', ['teknisi', 'koordinator'])
            ->where('filters.approval_status', 'pending')
            ->missing('users.data.0.google_id')->missing('users.data.0.password')
            ->missing('users.data.0.remember_token')->missing('users.data.0.two_factor_secret')->etc());
        $next = $response->inertiaProps('users.next_page_url');
        $this->assertStringContainsString('approval_status=pending', $next);
        $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page->has('users.data', 3)
            ->where('users.current_page', 2)->where('users.total', 13)->etc());
    }

    /** @return array<string, array{string}> */
    public static function approvalStates(): array
    {
        return ['pending' => ['pending'], 'approved' => ['approved'], 'rejected' => ['rejected']];
    }

    #[DataProvider('approvalStates')]
    public function test_each_approval_filter_returns_only_matching_accounts(string $status): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (User::APPROVAL_STATUSES as $approvalStatus) {
            User::factory()->create([
                'name' => 'Filter Target', 'role' => 'teknisi',
                'approval_status' => $approvalStatus, 'is_active' => false,
            ]);
        }

        $this->actingAs($admin)->get(route('admin.users.index', [
            'approval_status' => $status, 'search' => 'Filter', 'role' => 'teknisi', 'status' => 'inactive',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)->where('users.data.0.approval_status', $status)
            ->where('users.data.0.can_review', false)->etc());
    }

    public function test_invalid_approval_filter_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.users.index', ['approval_status' => 'invalid']))
            ->assertSessionHasErrors('approval_status');
    }

    private function pendingUser(): User
    {
        return User::factory()->create([
            'google_id' => 'google-pending', 'role' => null,
            'approval_status' => 'pending', 'is_active' => false,
        ]);
    }
}
