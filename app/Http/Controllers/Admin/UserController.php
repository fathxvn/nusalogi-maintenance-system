<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(User::ROLES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $filters = [
            'search' => trim($validated['search'] ?? ''),
            'role' => $validated['role'] ?? '',
            'status' => $validated['status'] ?? '',
        ];
        $users = User::query()->select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $query) use ($filters): void {
                    $query->where('name', 'like', '%'.$filters['search'].'%')
                        ->orWhere('email', 'like', '%'.$filters['search'].'%');
                });
            })
            ->when($filters['role'] !== '', fn (Builder $query) => $query->where('role', $filters['role']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->latest('id')->paginate(10)->withQueryString()
            ->through(fn (User $user): array => [
                ...$user->only(['id', 'name', 'email', 'role', 'is_active']),
                'created_at' => $user->created_at?->toDateString(),
                'links' => [
                    'edit' => route('admin.users.edit', $user),
                    'status' => route('admin.users.update-status', $user),
                ],
                'is_self' => $request->user()->is($user),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'roles' => User::ROLES,
            'filters' => $filters,
            'links' => ['index' => route('admin.users.index'), 'create' => route('admin.users.create')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/create', [
            'roles' => User::ROLES,
            'links' => ['index' => route('admin.users.index'), 'store' => route('admin.users.store')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'role' => [
                'required',
                Rule::in(User::ROLES),
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(12),
            ],
        ]);

        $user = new User;

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->password = $validated['password'];
        $user->is_active = true;

        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Akun pengguna berhasil dibuat');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/edit', [
            'user' => $user->only(['id', 'name', 'email', 'role', 'is_active']),
            'links' => ['index' => route('admin.users.index'), 'update' => route('admin.users.update-role', $user)],
            'roles' => User::ROLES,
        ]);
    }

    // update role pengguna
    public function updateRole(
        Request $request,
        User $user
    ): RedirectResponse {
        $validated = $request->validate([
            'role' => [
                'required',
                Rule::in(User::ROLES),
            ],
        ]);

        if ($request->user()->is($user)) {
            return back()->withErrors([
                'role' => 'anda tidak dapat mengubah role akun sendiri.',
            ]);
        }

        if (
            $user->role === 'admin'
            && $user->isActive()
            && $validated['role'] !== 'admin'
        ) {
            $activeAdminCount = User::query()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->count();

            if ($activeAdminCount <= 1) {
                return back()->withErrors([
                    'role' => 'Admin aktif terakhir tidak bole diturunkan.',
                ]);
            }
        }

        $user->role = $validated['role'];
        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Role pengguna berhasil diperbarui.');
    }

    public function updateStatus(
        Request $request,
        User $user
    ): RedirectResponse {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $newStatus = (bool) $validated['is_active'];

        if ($request->user()->is($user) && ! $newStatus) {
            return back()->withErrors([
                'is_active' => 'Anda tidak dapat menonaktifkan akun sendiri.',
            ]);
        }

        if (
            $user->role === 'admin'
            && $user->isActive()
            && ! $newStatus
        ) {
            $activeAdminCount = User::query()
                ->where('role', 'admin')
                ->where('is_active',
                    true)
                ->count();

            if ($activeAdminCount <= 1) {

                return back()->withErrors([
                    'is_active' => 'Admin aktif terkahir anda tidak boleh dinonaktifkan.',
                ]);
            }
        }

        $user->is_active = $newStatus;
        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with(
                'success',
                $newStatus
                    ? 'Akun pengguna berhasil diaktifkan.'
                    : 'Akun pengguna berhasil dinonaktifkan.'
            );
    }
}
