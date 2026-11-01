<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]]);
    }

    public function test_redirects_to_google_with_session_state(): void
    {
        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame('openid profile email', $query['scope']);
    }

    public function test_new_google_user_is_registered_pending_without_authentication(): void
    {
        $this->freezeSecond();
        Socialite::fake('google', $this->identity());

        $this->get($this->callbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Pendaftaran Google berhasil. Persetujuan administrator diperlukan sebelum Anda dapat masuk.');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $user = User::query()->sole();
        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('Google User', $user->name);
        $this->assertSame('google@example.test', $user->email);
        $this->assertTrue($user->email_verified_at->equalTo(now()));
        $this->assertNull($user->role);
        $this->assertFalse($user->is_active);
        $this->assertSame('pending', $user->approval_status);
        $this->assertTrue(Hash::isHashed($user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_missing_profile_name_uses_safe_fallback(): void
    {
        Socialite::fake('google', $this->identity(['name' => null]));

        $this->get($this->callbackUrl())->assertRedirect(route('login'));

        $this->assertSame('Pengguna Google', User::query()->sole()->name);
        $this->assertGuest();
    }

    public function test_approved_linked_user_logs_in_and_session_is_regenerated_without_changing_profile(): void
    {
        $user = User::factory()->create([
            'google_id' => 'google-123',
            'approval_status' => 'approved',
            'is_active' => true,
            'role' => null,
        ]);
        Socialite::fake('google', $this->identity());
        $this->withSession(['session-marker' => 'kept']);
        $sessionId = session()->getId();
        $csrfToken = session()->token();

        $this->get($this->callbackUrl())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(Auth::guard('web')->getName(), $user->id);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertNull($user->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    /** @return array<string, array{string, bool}> */
    public static function restrictedAccounts(): array
    {
        return [
            'pending active' => ['pending', true],
            'pending inactive' => ['pending', false],
            'rejected' => ['rejected', true],
            'inactive approved' => ['approved', false],
        ];
    }

    #[DataProvider('restrictedAccounts')]
    public function test_restricted_linked_account_cannot_login(string $approvalStatus, bool $isActive): void
    {
        $user = User::factory()->create([
            'google_id' => 'google-123',
            'approval_status' => $approvalStatus,
            'is_active' => $isActive,
        ]);
        Socialite::fake('google', $this->identity());

        $this->get($this->callbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Akun belum disetujui atau tidak aktif. Hubungi administrator.']);

        $this->assertGuest();
        $this->assertSame($approvalStatus, $user->fresh()->approval_status);
        $this->assertSame($isActive, $user->fresh()->is_active);
    }

    /** @return array<string, array{?string}> */
    public static function emailConflicts(): array
    {
        return ['local account' => [null], 'another Google account' => ['different-google-id']];
    }

    #[DataProvider('emailConflicts')]
    public function test_email_match_does_not_link_or_overwrite_existing_account(?string $googleId): void
    {
        $user = User::factory()->create([
            'email' => 'google@example.test',
            'google_id' => $googleId,
        ]);
        $attributes = $user->fresh()->getAttributes();
        Socialite::fake('google', $this->identity());

        $this->get($this->callbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Akun sudah terdaftar. Gunakan metode login semula atau hubungi administrator; penautan otomatis tidak diizinkan.']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($attributes, $user->fresh()->getAttributes());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidIdentities(): array
    {
        return [
            'missing ID' => [['id' => null]],
            'blank ID' => [['id' => ' ']],
            'oversized ID' => [['id' => str_repeat('x', 256)]],
            'missing email' => [['email' => null]],
            'invalid email' => [['email' => 'invalid-email']],
            'unverified' => [['email_verified' => false]],
            'missing verification' => [['email_verified' => null]],
            'string false' => [['email_verified' => 'false']],
            'legacy flag only' => [['email_verified' => null, 'verified_email' => true]],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('invalidIdentities')]
    public function test_invalid_provider_identity_cannot_create_or_authenticate_account(array $attributes): void
    {
        Socialite::fake('google', $this->identity($attributes));

        $this->get($this->callbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Identitas Google tidak lengkap atau email belum terverifikasi.']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_provider_failure_is_safe_and_does_not_authenticate(): void
    {
        Log::spy();
        Socialite::fake('google', function (): never {
            throw new RuntimeException('secret-access-token');
        });

        $response = $this->get($this->callbackUrl());

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Login Google gagal. Silakan mulai kembali.'])
            ->assertDontSee('secret-access-token');
        Log::shouldHaveReceived('warning')->once()->with('Google OAuth identity retrieval failed.');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_identity_cannot_authenticate_an_approved_linked_user(): void
    {
        User::factory()->create([
            'google_id' => 'google-123',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);
        Socialite::fake('google', $this->identity(['email_verified' => false]));

        $this->get($this->callbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Identitas Google tidak lengkap atau email belum terverifikasi.']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_redirect_provider_failure_returns_safe_feedback(): void
    {
        Socialite::shouldReceive('driver')->once()->with('google')->andThrow(new RuntimeException('secret'));

        $this->get(route('auth.google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Login Google tidak tersedia saat ini. Silakan coba lagi.']);

        $this->assertGuest();
    }

    public function test_authenticated_users_cannot_switch_accounts_through_callback(): void
    {
        $user = User::factory()->create();
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($user)->get($this->callbackUrl())
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidCallbacks(): array
    {
        return [
            'cancelled' => [['error' => 'access_denied']],
            'missing code' => [[]],
            'invalid code' => [['code' => ['invalid']]],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidCallbacks')]
    public function test_invalid_or_cancelled_callback_does_not_contact_google(array $query): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->withSession(['state' => 'oauth-state'])
            ->get(route('auth.google.callback', $query))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('state');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_real_provider_rejects_mismatched_session_state_before_http_request(): void
    {
        $this->mockGoogleHttp([]);

        $this->withSession(['state' => 'expected-state'])
            ->get(route('auth.google.callback', ['code' => 'code', 'state' => 'wrong-state']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('state');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_real_provider_maps_google_verified_email_and_consumes_state(): void
    {
        $this->mockGoogleHttp([
            new Response(200, [], json_encode(['access_token' => 'test-token', 'token_type' => 'Bearer'])),
            new Response(200, [], json_encode([
                'sub' => 'google-123',
                'name' => 'Google User',
                'email' => 'google@example.test',
                'email_verified' => true,
            ])),
        ]);

        $this->withSession(['state' => 'expected-state'])
            ->get(route('auth.google.callback', ['code' => 'code', 'state' => 'expected-state']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status')
            ->assertSessionMissing('state');

        $this->assertDatabaseHas('users', ['google_id' => 'google-123', 'approval_status' => 'pending']);
        $this->assertGuest();
    }

    /** @return array<string, array{string, string}> */
    public static function missingConfiguration(): array
    {
        return [
            'redirect client ID' => ['auth.google.redirect', 'client_id'],
            'redirect secret' => ['auth.google.redirect', 'client_secret'],
            'redirect URI' => ['auth.google.redirect', 'redirect'],
            'callback configuration' => ['auth.google.callback', 'client_secret'],
        ];
    }

    #[DataProvider('missingConfiguration')]
    public function test_missing_configuration_fails_safely(string $routeName, string $key): void
    {
        config(['services.google.'.$key => '']);
        Socialite::shouldReceive('driver')->never();

        $this->get(route($routeName, ['code' => 'code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Login Google belum tersedia. Silakan gunakan login email.']);

        $this->assertGuest();
    }

    public function test_repeated_registration_does_not_create_duplicate_accounts(): void
    {
        Socialite::fake('google', $this->identity());
        $this->get($this->callbackUrl())->assertSessionHas('status');

        $this->get($this->callbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    /** @return array<string, array{string}> */
    public static function concurrentConflicts(): array
    {
        return ['same Google ID' => ['google_id'], 'same email' => ['email']];
    }

    #[DataProvider('concurrentConflicts')]
    public function test_concurrent_registration_unique_conflict_is_handled_safely(string $column): void
    {
        Socialite::fake('google', $this->identity());
        Event::listen('eloquent.creating: '.User::class, function (User $user) use ($column): void {
            $attributes = $user->getAttributes();
            $attributes['google_id'] = $column === 'google_id' ? $user->google_id : 'other-google-id';
            $attributes['email'] = $column === 'email' ? $user->email : 'other@example.test';
            DB::table('users')->insert($attributes);
        });

        $this->get($this->callbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    /** @param array<int, Response> $responses */
    private function mockGoogleHttp(array $responses): void
    {
        Socialite::extend('google', function (Application $app) use ($responses): GoogleProvider {
            $provider = new GoogleProvider(
                $app['request'],
                config('services.google.client_id'),
                config('services.google.client_secret'),
                config('services.google.redirect'),
            );

            return $provider->setHttpClient(new Client([
                'handler' => HandlerStack::create(new MockHandler($responses)),
            ]));
        });
    }

    /** @param array<string, mixed> $attributes */
    private function identity(array $attributes = []): GoogleUser
    {
        $profile = array_replace([
            'id' => 'google-123',
            'name' => 'Google User',
            'email' => 'google@example.test',
            'email_verified' => true,
        ], $attributes);

        return (new GoogleUser)->setRaw($profile)->map($profile);
    }

    private function callbackUrl(): string
    {
        return route('auth.google.callback', ['code' => 'test-code', 'state' => 'test-state']);
    }
}
