<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Manajemen Pengguna - Nusalogi</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 p-6">

<main class="mx-auto max-w-6xl">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">
                Manajemen Pengguna
            </h1>
            <p class="text-sm text-slate-500">
                Kelola akun internal Nusalogi.
            </p>
        </div>

        <a href="{{ route('admin.users.create') }}"
           class="rounded-lg bg-blue-700 px-4 py-2 text-white">
            + Tambah Pengguna
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg bg-green-100 p-4 text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->has('is_acive'))
    <div class="mb-4 rounded-lg bg-red-100 p-4 text-red-700">
        {{ $errors->first('is_active') }}
    </div>
    @endif

    <div class="overflow-x-auto rounded-xl bg-white shadow">

        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="p-4">Nama</th>
                    <th class="p-4">Email</th>
                    <th class="p-4">Role</th>
                    <th class="p-4">Status</th>
                    <th class="p-4">Dibuat</th>
                    <th class="p-4">Aksi</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $user)
                    <tr>
                        <td class="p-4 font-medium">
                            {{ $user->name }}
                        </td>

                        <td class="p-4">
                            {{ $user->email }}
                        </td>

                        <td class="p-4">
                            {{ ucfirst($user->role ?? 'Belum ditentukan') }}
                        </td>

                        <td class="p-4">
                            @if ($user->isActive())
                                <span class="text-green-700">
                                    Aktif
                                </span>
                            @else
                                <span class="text-red-700">
                                    Nonaktif
                                </span>
                            @endif
                        </td>

                        <td class="p-4">
                            {{ $user->created_at->format('d/m/Y') }}
                        </td>

                        <td class="p-4">
                            <div class="flex flex-wrap items-center gap-3">

                                {{-- edit role --}}
                            <a href="{{ route('admin.users.edit', $user) }}"
                            class="font-medium text-blue-700 hover::underline">
                            Edit Role
                            </a>

                            {{-- Active / Deactice --}}
                            @if (auth()->id() !== $user->id)
                            <form
                                action="{{ route('admin.users.update-status', $user) }}"
                                method="POST"
                                >
                            @csrf
                            @method('PATCH')

                            <input
                                type="hidden"
                                name="is_active"
                                value="{{ $user->isActive() ? '0' : '1'}}"
                                >

                            <button
                                type="submit"
                                class="rounded-lg px-3 py-2 text-sm font-medium
                                {{ $user->isActive()
                                ? 'bg-red-100 text-red-700 hover::bg-green-200'
                                : 'bg-green-100 text-green-700 hover::bg-green-200' }}"
                                >
                                {{ $user->isActive()
                                ? 'Nonaktifkan'
                                : 'Aktifkan' }}
                                </button>
                            </form>
                            @else
                            <span class="text-xs text-slate-400">
                                Akun anda
                            </span>
                            @endif
                        </div>
                    </td>

                    </tr>
                @empty
                    <tr>
                        <td colspan="5"
                            class="p-8 text-center text-slate-500">
                            Belum ada pengguna.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    </div>

    <div class="mt-5">
        {{ $users->links() }}
    </div>

</main>
</body>
</html>
