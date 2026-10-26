<x-auth-layout title="Lupa Password">
    <div><h1 class="text-2xl font-semibold tracking-tight">Lupa Password</h1><p class="mt-2 text-sm leading-6 text-muted-foreground">Masukkan email akun internal untuk meminta tautan pemulihan password.</p></div>
    <form action="{{ route('password.email') }}" method="POST" class="flex flex-col gap-5" data-auth-form>
        @csrf
        <div class="flex flex-col gap-2">
            <label for="email" class="text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus class="field-control" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @error('email') aria-describedby="email-error" @enderror>
            @error('email')<p id="email-error" role="alert" class="text-sm text-destructive">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="auth-submit" data-submit-label>Kirim Tautan Reset Password</button>
    </form>
    <a href="{{ route('login') }}" class="self-start text-sm text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring">Kembali ke Login</a>
</x-auth-layout>
