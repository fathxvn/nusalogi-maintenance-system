<a href="{{ route('admin.users.create') }}"
   class="inline-block rounded bg-blue-700 px-4 py-2 text-white">
    + Tambah Pengguna
</a>

@if (session('success'))
    <div class="my-4 rounded bg-green-100 p-3 text-green-800">
        {{ session('success') }}
    </div>
@endif