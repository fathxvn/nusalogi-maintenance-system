<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserApprovalController extends Controller
{
    public function approve(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(User::GOOGLE_APPROVAL_ROLES)],
        ]);

        $this->review($request, $user, [
            'approval_status' => 'approved',
            'is_active' => true,
            'role' => $validated['role'],
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', 'Pendaftaran disetujui. Akun pengguna telah diaktifkan.');
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        $this->review($request, $user, [
            'approval_status' => 'rejected',
            'is_active' => false,
            'role' => null,
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', 'Pendaftaran ditolak. Akun tetap nonaktif dan tersimpan untuk peninjauan.');
    }

    /**
     * @param  array{approval_status: string, is_active: bool, role: ?string}  $attributes
     */
    private function review(Request $request, User $user, array $attributes): void
    {
        $updated = User::query()->whereKey($user->id)
            ->where('id', '!=', $request->user()->id)
            ->whereNotNull('google_id')
            ->where('approval_status', 'pending')
            ->update($attributes);

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'approval_status' => 'Hanya pendaftaran Google yang masih menunggu dapat ditinjau. Muat ulang daftar pengguna.',
            ]);
        }
    }
}
