
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Nusalogi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 p-8">

    <div class="mx-auto max-w-4xl rounded-xl bg-white p-8 shadow">

        <h1 class="text-2xl font-bold">
            Dashboard Nusalogi
        </h1>

        <p class="mt-3">
            Selamat datang, {{ auth()->user()->name }}!
        </p>

        <p class="mt-2 text-slate-500">
            Authentication berhasil. Role Management menyusul.
        </p>

        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf

            <button
                type="submit"
                class="rounded-lg bg-red-600 px-4 py-2 text-white"
            >
                Logout
            </button>
        </form>

    </div>

</body>
</html>
