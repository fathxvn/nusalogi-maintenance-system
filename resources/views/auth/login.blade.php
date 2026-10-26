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
</x-auth-layout>
