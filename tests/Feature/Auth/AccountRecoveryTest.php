<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'NewTestingPassword123!';

    public function test_login_has_recovery_link_outside_form(): void
    {
        $response = $this->get(route('login'))->assertOk()->assertViewIs('auth.login');
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//a[@href="'.route('password.request').'"]');

        $this->assertCount(1, $links);
        $this->assertSame(0, $xpath->query('ancestor::form', $links->item(0))->length);
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get(route('password.request'))->assertOk()->assertViewIs('auth.forgot-password');
    }

    public function test_reset_page_preserves_token_and_email_and_prevents_caching_and_referrers(): void
    {
        $this->get(route('password.reset', ['token' => 'test-token', 'email' => 'user@example.test']))
            ->assertOk()
            ->assertViewIs('auth.reset-password')
            ->assertSee('value="test-token"', false)
            ->assertSee('value="user@example.test"', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_forgot_password_sends_a_working_link_and_stores_only_a_hashed_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);
        $storedToken = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

        $this->assertTrue(Hash::check($token, $storedToken));
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $notification = Notification::sent($user, ResetPassword::class)->first();
        $this->assertSame(
            route('password.reset', ['token' => $token, 'email' => $user->email]),
            $notification->toMail($user)->actionUrl,
        );
    }

    public function test_forgot_password_hides_unknown_and_throttled_accounts_in_html(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $message = 'Jika email terdaftar, tautan pemulihan password akan dikirim. Silakan periksa email Anda.';

        foreach ([$user->email, $user->email, 'missing@example.test'] as $email) {
            $this->post(route('password.email'), ['email' => $email])
                ->assertRedirect(route('password.request'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $message);
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_forgot_password_hides_unknown_and_throttled_accounts_in_json(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $expected = ['message' => 'Jika email terdaftar, tautan pemulihan password akan dikirim. Silakan periksa email Anda.'];

        foreach ([$user->email, $user->email, 'missing@example.test'] as $email) {
            $this->postJson(route('password.email'), ['email' => $email])->assertOk()->assertExactJson($expected);
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_forgot_password_validates_email(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'invalid'])->assertSessionHasErrors('email');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_successful_reset_hashes_password_rotates_remember_token_and_consumes_reset_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['is_active' => true]);
        $rememberToken = $user->remember_token;
        $token = $this->requestResetToken($user);
        Event::fake([PasswordResetEvent::class]);

        $this->post(route('password.update'), $this->resetPayload($user, $token))
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.reset'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest();
        Event::assertDispatched(PasswordResetEvent::class, fn (PasswordResetEvent $event): bool => $event->user->is($user));
    }

    public function test_invalid_token_does_not_change_password_or_consume_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $this->requestResetToken($user);

        $this->post(route('password.update'), $this->resetPayload($user, 'invalid-token'))
            ->assertSessionHasErrors('email');

        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest();
    }

    public function test_expired_token_is_rejected(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $token = $this->requestResetToken($user);
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->post(route('password.update'), $this->resetPayload($user, $token))->assertSessionHasErrors('email');

        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_consumed_token_cannot_be_reused(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);
        $this->post(route('password.update'), $this->resetPayload($user, $token))->assertSessionHasNoErrors();
        $passwordAfterReset = $user->fresh()->password;

        $this->post(route('password.update'), [
            ...$this->resetPayload($user, $token),
            'password' => 'AnotherTestingPassword123!',
            'password_confirmation' => 'AnotherTestingPassword123!',
        ])->assertSessionHasErrors('email');

        $this->assertSame($passwordAfterReset, $user->fresh()->password);
    }

    public function test_old_password_is_rejected_and_new_password_can_login_after_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['is_active' => true]);
        $token = $this->requestResetToken($user);
        $this->post(route('password.update'), $this->resetPayload($user, $token))->assertSessionHasNoErrors();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_account_can_reset_password_but_cannot_login(): void
    {
        Notification::fake();
        $user = User::factory()->create(['is_active' => false]);
        $token = $this->requestResetToken($user);
        $this->post(route('password.update'), $this->resetPayload($user, $token))->assertSessionHasNoErrors();
        $this->assertGuest();
        $this->assertFalse($user->fresh()->isActive());
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_token_cannot_reset_a_different_account_and_failure_hides_account_existence(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->requestResetToken($owner);
        $originalPassword = $other->password;
        $error = 'Tautan pemulihan tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.';

        foreach ([$other->email, 'missing@example.test'] as $email) {
            $this->postJson(route('password.update'), [...$this->resetPayload($owner, $token), 'email' => $email])
                ->assertUnprocessable()->assertJsonValidationErrors(['email' => $error]);
        }

        $this->assertSame($originalPassword, $other->fresh()->password);
        $this->assertTrue(Password::broker('users')->tokenExists($owner, $token));
    }

    #[DataProvider('invalidResetInputs')]
    public function test_reset_validates_input_and_keeps_secrets_out_of_old_input(array $overrides, string $field): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $token = $this->requestResetToken($user);

        $this->from(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->post(route('password.update'), [...$this->resetPayload($user, $token), ...$overrides])
            ->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.token')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
    }

    public static function invalidResetInputs(): array
    {
        return [
            'missing token' => [['token' => ''], 'token'],
            'array token' => [['token' => ['invalid']], 'token'],
            'missing email' => [['email' => ''], 'email'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'missing password' => [['password' => ''], 'password'],
            'array password' => [['password' => ['invalid']], 'password'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'missing confirmation' => [['password_confirmation' => ''], 'password'],
            'different confirmation' => [['password_confirmation' => 'DifferentPassword123!'], 'password'],
        ];
    }

    public function test_replacement_token_invalidates_previous_token(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create();
        $token = $this->requestResetToken($user);
        $this->travel(config('auth.passwords.users.throttle') + 1)->seconds();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

        $this->post(route('password.update'), $this->resetPayload($user, $token))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[DataProvider('recoveryPostRoutes')]
    public function test_recovery_posts_are_rate_limited_by_ip(string $routeName): void
    {
        Notification::fake();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route($routeName), [])->assertUnprocessable();
        }
        $this->postJson(route($routeName), [])->assertTooManyRequests();
        Notification::assertNothingSent();
    }

    public static function recoveryPostRoutes(): array
    {
        return ['forgot password' => ['password.email'], 'reset password' => ['password.update']];
    }

    public function test_public_registration_remains_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_login_supports_remember_me_with_the_existing_session_guard(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('dashboard'))->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_inertia_guest_navigation_to_dashboard_loads_the_blade_login_document(): void
    {
        $this->followingRedirects()->withHeader('X-Inertia', 'true')->get(route('dashboard'))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
        $this->assertGuest();
    }

    public function test_inertia_recovery_navigation_does_not_serialize_the_reset_token(): void
    {
        $url = route('password.reset', ['token' => 'test-placeholder', 'email' => 'test@example.test']);
        $response = $this->withHeader('X-Inertia', 'true')->get($url)
            ->assertStatus(409)->assertHeader('X-Inertia-Location', $url)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('', $response->getContent());
    }

    public function test_inactive_inertia_session_returns_to_the_native_login_document(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->followingRedirects()->withHeader('X-Inertia', 'true')->get(route('dashboard'))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
        $this->assertGuest();
    }

    private function requestResetToken(User $user): string
    {
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);

        return Notification::sent($user, ResetPassword::class)->last()->token;
    }

    /** @return array{token: string, email: string, password: string, password_confirmation: string} */
    private function resetPayload(User $user, string $token): array
    {
        return [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];
    }
}
