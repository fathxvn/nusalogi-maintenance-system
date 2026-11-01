<x-auth-layout title="Masuk" :split="true">
    <div><h1 class="text-2xl font-semibold tracking-tight">Masuk ke ruang kerja</h1><p class="mt-2 text-sm leading-6 text-muted-foreground">Gunakan akun internal Nusalogi Anda.</p></div>
    <form action="{{ route('login.store') }}" method="POST" class="flex flex-col gap-5" data-auth-form>
        @csrf
        <div class="flex flex-col gap-2">
            <label for="email" class="text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus class="field-control" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @error('email') aria-describedby="email-error" @enderror>
            @error('email')<p id="email-error" role="alert" class="text-sm text-destructive">{{ $message }}</p>@enderror
        </div>
        <x-password-field id="password" label="Password" autocomplete="current-password" />
        <label class="flex min-h-6 items-center gap-2.5 text-sm text-muted-foreground"><input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded accent-primary focus-visible:outline-2 focus-visible:outline-ring">Ingat saya</label>
        <button type="submit" class="auth-submit" data-submit-label>Masuk</button>
    </form>
    <a href="{{ route('password.request') }}" class="self-start text-sm text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring">Lupa Password</a>
    <div class="flex items-center gap-3 text-xs text-muted-foreground" aria-hidden="true">
        <span class="h-px flex-1 bg-border"></span>
        <span>atau</span>
        <span class="h-px flex-1 bg-border"></span>
    </div>
    <div class="flex flex-col gap-3">
        <a href="{{ route('auth.google.redirect') }}" aria-describedby="google-approval-notice" class="inline-flex min-h-11 w-full items-center justify-center gap-3 rounded-md border border-border bg-background px-4 py-3 text-sm font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
            <svg viewBox="0 0 24 24" class="size-5 shrink-0" aria-hidden="true" focusable="false">
                <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.39-.18-2.05H12v3.88h5.38a4.6 4.6 0 0 1-1.99 3.02v2.51h3.23c1.89-1.74 2.98-4.3 2.98-7.36Z" />
                <path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.41l-3.23-2.51c-.9.6-2.05.96-3.39.96-2.6 0-4.81-1.76-5.6-4.12H3.06v2.59A10 10 0 0 0 12 22Z" />
                <path fill="#FBBC05" d="M6.4 13.92a6 6 0 0 1 0-3.84V7.49H3.06a10 10 0 0 0 0 9.02l3.34-2.59Z" />
                <path fill="#EA4335" d="M12 5.96c1.47 0 2.79.5 3.82 1.49l2.86-2.87A9.6 9.6 0 0 0 12 2a10 10 0 0 0-8.94 5.49l3.34 2.59C7.19 7.72 9.4 5.96 12 5.96Z" />
            </svg>
            Lanjutkan dengan Google
        </a>
        <p id="google-approval-notice" class="text-center text-xs leading-5 text-muted-foreground">Pendaftaran baru dengan Google memerlukan persetujuan Admin sebelum dapat masuk.</p>
    </div>
</x-auth-layout>
