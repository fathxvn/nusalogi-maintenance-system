<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->failure('Login Google belum tersedia. Silakan gunakan login email.');
        }

        try {
            return Socialite::driver('google')->redirect();
        } catch (Throwable) {
            Log::warning('Google OAuth redirect failed.');

            return $this->failure('Login Google tidak tersedia saat ini. Silakan coba lagi.');
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->failure('Login Google belum tersedia. Silakan gunakan login email.');
        }

        if ($request->has('error')) {
            $request->session()->forget('state');

            return $this->failure('Login Google dibatalkan atau tidak diizinkan. Silakan coba lagi.');
        }

        if (! is_string($request->query('code')) || trim($request->query('code')) === '') {
            $request->session()->forget('state');

            return $this->failure('Sesi login Google tidak valid. Silakan mulai kembali.');
        }

        try {
            $identity = Socialite::driver('google')->user();
        } catch (Throwable) {
            Log::warning('Google OAuth identity retrieval failed.');

            return $this->failure('Login Google gagal. Silakan mulai kembali.');
        }

        $googleId = $identity->getId();
        $email = $identity->getEmail();

        if (Validator::make([
            'google_id' => $googleId,
            'email' => $email,
        ], [
            'google_id' => ['required', 'string', 'max:255', 'regex:/\A[^\s\x00-\x1F\x7F]+\z/u'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->fails() || ($identity->user['email_verified'] ?? null) !== true) {
            return $this->failure('Identitas Google tidak lengkap atau email belum terverifikasi.');
        }

        $user = User::query()->where('google_id', $googleId)->first();

        if ($user) {
            if (! $user->isActive() || ! $user->isApproved()) {
                return $this->failure('Akun belum disetujui atau tidak aktif. Hubungi administrator.');
            }

            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return redirect()->route('dashboard');
        }

        if (User::query()->where('email', $email)->exists()) {
            return $this->emailConflict();
        }

        $name = $identity->getName();
        $user = new User;
        $user->google_id = $googleId;
        $user->name = is_string($name) && trim($name) !== ''
            ? mb_substr(trim($name), 0, 255)
            : 'Pengguna Google';
        $user->email = $email;
        $user->email_verified_at = now();
        $user->password = Str::random(64);
        $user->role = null;
        $user->is_active = false;
        $user->approval_status = 'pending';

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            return $this->emailConflict();
        }

        return redirect()->route('login')
            ->with('status', 'Pendaftaran Google berhasil. Persetujuan administrator diperlukan sebelum Anda dapat masuk.');
    }

    private function isConfigured(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            $value = config('services.google.'.$key);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    private function emailConflict(): RedirectResponse
    {
        return $this->failure('Akun sudah terdaftar. Gunakan metode login semula atau hubungi administrator; penautan otomatis tidak diizinkan.');
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
