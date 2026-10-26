<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Reset Password - Nusalogi</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="flex min-h-screen items-center justify-center bg-slate-100 p-4">

    <main class="w-full max-w-md rounded-xl bg-white p-8 shadow-lg">

        <h1 class="text-2xl font-bold text-slate-900">
            Reset Password
        </h1>

        <p class="mt-2 text-sm text-slate-500">
            Masukkan password baru untuk akun Nusalogi.
        </p>

        <form action="{{ route('password.update') }}"
              method="POST"
              class="mt-6 space-y-4">

            @csrf

            <input type="hidden"
                   name="token"
                   value="{{ $request->route('token') }}">

            <div>
                <label for="email" class="block text-sm font-medium">
                    Email
                </label>

                <input type="email"
                       id="email"
                       name="email"
                       value="{{ old('email', $request->query('email')) }}"
                       autocomplete="email"
                       required
                       class="mt-2 w-full rounded-lg border border-slate-300 p-3">

                @error('email')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium">
                    Password Baru
                </label>

                <input type="password"
                       id="password"
                       name="password"
                       autocomplete="new-password"
                       required
                       class="mt-2 w-full rounded-lg border border-slate-300 p-3">

                @error('password')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation"
                       class="block text-sm font-medium">
                    Konfirmasi Password
                </label>

                <input type="password"
                       id="password_confirmation"
                       name="password_confirmation"
                       autocomplete="new-password"
                       required
                       class="mt-2 w-full rounded-lg border border-slate-300 p-3">
            </div>

            @error('token')
                <p class="text-sm text-red-600">
                    {{ $message }}
                </p>
            @enderror

            <button type="submit"
                    class="w-full rounded-lg bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800">
                Simpan Password Baru
            </button>

        </form>

        <a href="{{ route('login') }}"
           class="mt-5 block text-center text-sm text-blue-700 hover:underline">
            Kembali ke Login
        </a>

    </main>

</body>
</html>
