
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Tambah Pengguna - Nusalogi</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100 p-6">

<main class="mx-auto max-w-xl">

    <a href="{{ route('admin.users.index') }}"
       class="text-blue-700">
        ← Kembali ke daftar pengguna
    </a>

    <div class="mt-5 rounded-xl bg-white p-6 shadow">

        <h1 class="mb-6 text-2xl font-bold">
            Tambah Pengguna
        </h1>

        <form method="POST"
              action="{{ route('admin.users.store') }}"
              class="space-y-4">

            @csrf

            <div>
                <label for="name">Nama Lengkap</label>
                <input
                    id="name"
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    class="mt-1 w-full rounded border p-2"
                >
                @error('name')
                    <p class="text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    class="mt-1 w-full rounded border p-2"
                >
                @error('email')
                    <p class="text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="role">Role</label>
                <select
                    id="role"
                    name="role"
                    required
                    class="mt-1 w-full rounded border p-2"
                >
                    <option value="">Pilih Role</option>

                    @foreach ($roles as $role)
                        <option
                            value="{{ $role }}"
                            @selected(old('role') === $role)
                        >
                            {{ ucfirst($role) }}
                        </option>
                    @endforeach
                </select>

                @error('role')
                    <p class="text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password">
                    Password Sementara
                </label>

                <input
                    id="password"
                    type="password"
                    name="password"
                    minlength="12"
                    required
                    autocomplete="new-password"
                    class="mt-1 w-full rounded border p-2"
                >
                @error('password')
                    <p class="text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation">
                    Konfirmasi Password
                </label>

                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    minlength="12"
                    required
                    autocomplete="new-password"
                    class="mt-1 w-full rounded border p-2"
                >
            </div>

            <button
                type="submit"
                class="rounded bg-blue-700 px-5 py-2 text-white"
            >
                Simpan Pengguna
            </button>

        </form>
    </div>
</main>

</body>
</html>
