<x-auth-layout title="Reset Password">
    <div><h1 class="text-2xl font-semibold tracking-tight">Reset Password</h1><p class="mt-2 text-sm leading-6 text-muted-foreground">Masukkan dan konfirmasikan password baru untuk akun Anda.</p></div>
    <form action="{{ route('password.update') }}" method="POST" class="flex flex-col gap-5" data-auth-form>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div class="flex flex-col gap-2">
            <label for="email" class="text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->query('email')) }}" autocomplete="email" required class="field-control" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @error('email') aria-describedby="email-error" @enderror>
            @error('email')<p id="email-error" role="alert" class="text-sm text-destructive">{{ $message }}</p>@enderror
        </div>
        <x-password-field id="password" label="Password Baru" />
        <x-password-field id="password_confirmation" label="Konfirmasi Password" />
        @error('token')<p role="alert" class="text-sm text-destructive">{{ $message }}</p>@enderror
        <button type="submit" class="auth-submit" data-submit-label>Simpan Password Baru</button>
    </form>
    <a href="{{ route('login') }}" class="self-start text-sm text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring">Kembali ke Login</a>
</x-auth-layout>
