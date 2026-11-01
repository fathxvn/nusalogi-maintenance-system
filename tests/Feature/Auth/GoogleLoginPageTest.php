<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleLoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]]);
    }

    public function test_guest_login_has_native_google_link_outside_password_form(): void
    {
        $response = $this->get(route('login'))->assertOk()->assertViewIs('auth.login')
            ->assertSee('Lanjutkan dengan Google')
            ->assertSee('Pendaftaran baru dengan Google memerlukan persetujuan Admin sebelum dapat masuk.');
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//a[@href="'.route('auth.google.redirect').'"]');

        $this->assertCount(1, $links);
        $this->assertSame(0, $xpath->query('ancestor::form', $links->item(0))->length);
        $this->assertFalse($links->item(0)->hasAttribute('data-inertia'));
        $this->assertFalse($links->item(0)->hasAttribute('onclick'));
        $this->assertSame('google-approval-notice', $links->item(0)->getAttribute('aria-describedby'));
        $this->assertCount(1, $xpath->query('//form[@method="POST" and @action="'.route('login.store').'"]'));
        $this->assertCount(1, $xpath->query('//input[@name="remember" and @type="checkbox"]'));
        $this->assertCount(1, $xpath->query('//a[@href="'.route('password.request').'"]'));
        $this->assertGuest();
    }

    public function test_new_registration_feedback_reaches_blade_login_without_provider_html(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        Socialite::fake('google', $this->identity($payload));

        $this->followingRedirects()->get(route('auth.google.callback', ['code' => 'code']))
            ->assertOk()->assertViewIs('auth.login')
            ->assertSee('Pendaftaran Google berhasil. Persetujuan administrator diperlukan sebelum Anda dapat masuk.')
            ->assertSee('role="status"', false)
            ->assertDontSee($payload, false);

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['approval_status' => 'pending', 'is_active' => false, 'role' => null]);
    }

    /** @return array<string, array{string, bool}> */
    public static function restrictedAccounts(): array
    {
        return [
            'pending' => ['pending', false],
            'rejected' => ['rejected', false],
            'inactive' => ['approved', false],
        ];
    }

    #[DataProvider('restrictedAccounts')]
    public function test_restricted_account_callback_feedback_is_visible(string $status, bool $isActive): void
    {
        User::factory()->create([
            'google_id' => 'google-login', 'approval_status' => $status, 'is_active' => $isActive,
        ]);
        Socialite::fake('google', $this->identity());

        $this->followingRedirects()->get(route('auth.google.callback', ['code' => 'code']))
            ->assertOk()->assertViewIs('auth.login')
            ->assertSee('Akun belum disetujui atau tidak aktif. Hubungi administrator.')
            ->assertSee('role="alert"', false);

        $this->assertGuest();
    }

    public function test_existing_email_conflict_feedback_is_visible(): void
    {
        User::factory()->create(['email' => 'google@example.test']);
        Socialite::fake('google', $this->identity());

        $this->followingRedirects()->get(route('auth.google.callback', ['code' => 'code']))
            ->assertOk()->assertViewIs('auth.login')
            ->assertSee('Akun sudah terdaftar. Gunakan metode login semula atau hubungi administrator; penautan otomatis tidak diizinkan.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_oauth_cancellation_feedback_is_visible_without_echoing_provider_details(): void
    {
        $payload = '<script>alert(1)</script>';
        Socialite::shouldReceive('driver')->never();

        $this->followingRedirects()->get(route('auth.google.callback', [
            'error' => 'access_denied', 'error_description' => $payload,
        ]))->assertOk()->assertViewIs('auth.login')
            ->assertSee('Login Google dibatalkan atau tidak diizinkan. Silakan coba lagi.')
            ->assertDontSee($payload, false);

        $this->assertGuest();
    }

    public function test_provider_failure_feedback_hides_internal_exception_details(): void
    {
        Socialite::fake('google', function (): never {
            throw new RuntimeException('private-oauth-token');
        });

        $this->followingRedirects()->get(route('auth.google.callback', ['code' => 'code']))
            ->assertOk()->assertViewIs('auth.login')
            ->assertSee('Login Google gagal. Silakan mulai kembali.')
            ->assertDontSee('private-oauth-token');

        $this->assertGuest();
    }

    public function test_status_feedback_is_html_escaped(): void
    {
        $payload = '<script>alert("provider")</script>';

        $this->withSession(['status' => $payload])->get(route('login'))
            ->assertOk()->assertSee($payload)->assertDontSee($payload, false);
    }

    public function test_validation_feedback_is_html_escaped(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        Route::middleware('web')->get('/test-login-feedback', fn () => redirect()->route('login')->withErrors(['email' => $payload]));

        $this->followingRedirects()->get('/test-login-feedback')
            ->assertOk()->assertSee($payload)->assertDontSee($payload, false);
    }

    public function test_inertia_guest_login_hands_off_to_full_blade_document(): void
    {
        $this->withHeader('X-Inertia', 'true')->get(route('login'))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));

        $this->withHeaders(['X-Inertia' => ''])->get(route('login'))
            ->assertOk()->assertViewIs('auth.login')->assertHeaderMissing('X-Inertia')
            ->assertSee('<!DOCTYPE html>', false)->assertSee('Lanjutkan dengan Google');
        $this->assertGuest();
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function oauthFeedback(): array
    {
        return [
            'pending registration status' => [
                ['code' => 'code'],
                'Pendaftaran Google berhasil. Persetujuan administrator diperlukan sebelum Anda dapat masuk.',
            ],
            'cancellation validation error' => [
                ['error' => 'access_denied'],
                'Login Google dibatalkan atau tidak diizinkan. Silakan coba lagi.',
            ],
        ];
    }

    /** @param array<string, string> $query */
    #[DataProvider('oauthFeedback')]
    public function test_inertia_login_handoff_preserves_oauth_feedback(array $query, string $message): void
    {
        Socialite::fake('google', $this->identity());
        $this->get(route('auth.google.callback', $query))->assertRedirect(route('login'));

        $this->withHeader('X-Inertia', 'true')->get(route('login'))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));

        $this->withHeaders(['X-Inertia' => ''])->get(route('login'))
            ->assertOk()->assertViewIs('auth.login')->assertSee($message);
        $this->get(route('login'))->assertOk()->assertDontSee($message);
        $this->assertGuest();
    }

    private function identity(string $name = 'Google User'): GoogleUser
    {
        return (new GoogleUser)->setRaw(['email_verified' => true])->map([
            'id' => 'google-login', 'email' => 'google@example.test', 'name' => $name,
        ]);
    }
}
