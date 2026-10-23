<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $user = User::query()
            ->select([
                'id',
                'name',
                'role',
                'is_active',
                'created_at',
            ])

            ->latest('id')
            ->paginate(10);

        return view('admin.users.index', compact('user'));
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => User::ROLES,
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
}
