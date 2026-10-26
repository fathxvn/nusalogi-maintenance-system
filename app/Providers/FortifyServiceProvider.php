<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Middleware\SecureAccountRecovery;
use App\Http\Responses\FailedPasswordResetResponse;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetResponseContract::class, FailedPasswordResetResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('account-recovery', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        $this->app->booted(function (): void {
            foreach ($this->app['router']->getRoutes() as $route) {
                if ($route->named('password.request', 'password.email', 'password.reset', 'password.update')) {
                    if (in_array('POST', $route->methods(), true)) {
                        $route->middleware('throttle:account-recovery');
                    }

                    $route->middleware(SecureAccountRecovery::class);
                }
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Fortify User Actions
        |--------------------------------------------------------------------------
        */

        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::updateUserProfileInformationUsing(
            UpdateUserProfileInformation::class
        );

        Fortify::updateUserPasswordsUsing(
            UpdateUserPassword::class
        );

        Fortify::resetUserPasswordsUsing(
            ResetUserPassword::class
        );

        /*
        |--------------------------------------------------------------------------
        | Login View
        |--------------------------------------------------------------------------
        */

        Fortify::loginView(function () {
            return view('auth.login');
        });

        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });

        Fortify::resetPasswordView(function (Request $request) {
            return view('auth.reset-password', [
                'request' => $request,
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | Custom Authentication
        |--------------------------------------------------------------------------
        |
        | Hanya pengguna aktif dengan password benar yang dapat login.
        | Akun nonaktif tidak boleh membuat session baru.
        |
        */

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()
                ->where('email', $request->input('email'))
                ->first();

            if (! $user) {
                return null;
            }

            if (! $user->isActive()) {
                return null;
            }

            if (! Hash::check(
                (string) $request->input('password'),
                $user->password
            )) {
                return null;
            }

            return $user;
        });

        /*
        |--------------------------------------------------------------------------
        | Login Rate Limiting
        |--------------------------------------------------------------------------
        */

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(
                Str::lower(
                    (string) $request->input(Fortify::username())
                ).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        /*
        |--------------------------------------------------------------------------
        | Two-Factor Rate Limiting
        |--------------------------------------------------------------------------
        |
        | 2FA saat ini dinonaktifkan melalui config/fortify.php.
        | Konfigurasi rate limiter dipertahankan untuk pengembangan.
        |
        */

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->session()->get('login.id')
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Passkey Rate Limiting
        |--------------------------------------------------------------------------
        |
        | Passkey belum digunakan pada MVP Nusalogi.
        |
        */

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId())
                .'|'.$request->ip()
            );
        });
    }
}
