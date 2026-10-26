<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Edit Role - Nusalogi</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100 p-6">

    <main class="mx-auto max-w-xl">

        <a href="{{ route('admin.users.index') }}"
           class="text-sm font-medium text-blue-700">
            ← Kembali ke Daftar Pengguna
        </a>

        <div class="mt-6 rounded-xl bg-white p-6 shadow">

            <h1 class="text-2xl font-bold text-slate-900">
                Edit Role Pengguna
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                Perbarui hak akses pengguna Nusalogi.
            </p>

            <div class="mt-6 rounded-lg bg-slate-50 p-4">
                <p class="font-semibold">
                    {{ $user->name }}
                </p>

                <p class="text-sm text-slate-500">
                    {{ $user->email }}
                </p>
            </div>

            <form
                action="{{ route('admin.users.update-role', $user) }}"
                method="POST"
                class="mt-6 space-y-5"
            >
                @csrf
                @method('PATCH')

                <div>
                    <label for="role"
                           class="block text-sm font-medium">
                        Role Pengguna
                    </label>

                    <select
                        name="role"
                        id="role"
                        required
                        class="mt-2 w-full rounded-lg border border-slate-300 p-3"
                    >
                        @foreach ($roles as $role)
                            <option
                                value="{{ $role }}"
                                @selected(old('role', $user->role) === $role)
                            >
                                {{ ucfirst($role) }}
                            </option>
                        @endforeach
                    </select>

                    @error('role')
                        <p class="mt-2 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-blue-700 px-4 py-3 font-medium text-white hover:bg-blue-800"
                >
                    Simpan Perubahan
                </button>
            </form>

        </div>
    </main>

</body>
</html>
