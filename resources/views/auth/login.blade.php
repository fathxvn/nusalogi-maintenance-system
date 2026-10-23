
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Nusalogi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4">

    <div class="w-full max-w-md rounded-xl bg-white p-8 shadow-lg">

        <h1 class="text-2xl font-bold text-slate-900">
            Nusalogi Maintenance
        </h1>

        <p class="mt-2 text-sm text-slate-500">
            Silakan masuk menggunakan akun internal.
        </p>

        @if (session('status'))
            <p class="mt-4 text-green-600">
                {{ session('status') }}
            </p>
        @endif

        <form action="{{ route('login') }}" method="POST" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium">
                    Email
                </label>

                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    required
                    autofocus
                    class="mt-1 w-full rounded-lg border border-slate-300 p-3"
                >

                @error('email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium">
                    Password
                </label>

                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-300 p-3"
                >

                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full rounded-lg bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800"
            >
                Login
            </button>
        </form>
    </div>

</body>
</html>
