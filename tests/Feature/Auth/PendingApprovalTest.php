<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PendingApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_user_cannot_login_with_password(): void
    {
        $user = User::factory()->create([
            'role' => 'teknisi',
            'is_active' => true,
            'approval_status' => 'pending',
            'password' => 'TestingPassword123!',
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => $user->email,
            'password' => 'TestingPassword123!',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_pending_user_cannot_keep_session(): void
    {
        $user = User::factory()->create([
            'role' => 'teknisi',
            'is_active' => true,
            'approval_status' => 'pending',
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_approved_active_user_can_still_login(): void
    {
        $user = User::factory()->create([
            'role' => 'teknisi',
            'is_active' => true,
            'approval_status' => 'approved',
            'password' => 'TestingPassword123!',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'TestingPassword123!',
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function restrictedAccounts(): array
    {
        return [
            'inactive approved' => [false, 'approved'],
            'inactive pending' => [false, 'pending'],
        ];
    }

    #[DataProvider('restrictedAccounts')]
    public function test_inactive_user_cannot_login_regardless_of_approval(bool $isActive, string $approvalStatus): void
    {
        $user = User::factory()->create([
            'is_active' => $isActive,
            'approval_status' => $approvalStatus,
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    /**
     * @return array<string, array{string, array<string, bool|string>}>
     */
    public static function revokedAccess(): array
    {
        return [
            'pending dashboard' => ['dashboard', ['approval_status' => 'pending']],
            'pending admin' => ['admin.users.index', ['approval_status' => 'pending']],
            'inactive dashboard' => ['dashboard', ['is_active' => false]],
            'inactive admin' => ['admin.users.index', ['is_active' => false]],
        ];
    }

    /**
     * @param  array<string, bool|string>  $attributes
     */
    #[DataProvider('revokedAccess')]
    public function test_revoked_account_loses_session_before_accessing_protected_routes(string $routeName, array $attributes): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'approval_status' => 'approved',
        ]);

        $guard = Auth::guard('web');
        $this->actingAs($user)->withSession([
            $guard->getName() => $user->id,
            'private-session-data' => 'must be removed',
        ]);
        $sessionId = session()->getId();
        $csrfToken = session()->token();

        User::query()->whereKey($user->id)->update($attributes);

        $this->get(route($routeName))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing($guard->getName())
            ->assertSessionMissing('private-session-data');

        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());
    }

    public function test_approved_active_user_can_access_dashboard_with_session(): void
    {
        $user = User::factory()->create([
            'role' => 'teknisi',
            'is_active' => true,
            'approval_status' => 'approved',
        ]);

        $this->withSession([Auth::guard('web')->getName() => $user->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }
}
