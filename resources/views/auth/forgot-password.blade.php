<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Lupa Password - Nusalogi</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="flex min-h-screen items-center justify-center bg-slate-100 p-4">

    <main class="w-full max-w-md rounded-xl bg-white p-8 shadow-lg">

        <h1 class="text-2xl font-bold text-slate-900">
            Lupa Password?
        </h1>

        <p class="mt-2 text-sm text-slate-500">
            Masukkan email akun Nusalogi untuk meminta
            tautan pemulihan password.
        </p>

        @if (session('status'))
            <div role="status" class="mt-4 rounded-lg bg-green-100 p-3 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        @error('email')
            <div role="alert" class="mt-4 rounded-lg bg-red-100 p-3 text-sm text-red-700">
                {{ $message }}
            </div>
        @enderror

        <form action="{{ route('password.email') }}"
              method="POST"
              class="mt-6 space-y-4">

            @csrf

            <div>
                <label for="email"
                       class="block text-sm font-medium text-slate-700">
                    Email
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-300 p-3"
                >
            </div>

            <button
                type="submit"
                class="w-full rounded-lg bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800"
            >
                Kirim Tautan Reset Password
            </button>
        </form>

        <a href="{{ route('login') }}"
           class="mt-5 block text-center text-sm text-blue-700 hover:underline">
            Kembali ke Login
        </a>

    </main>
</body>
</html>
