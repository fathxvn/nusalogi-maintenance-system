<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(bool $active): User
    {
        return User::factory()->create([
            'email' => 'admin-test@example.test',
            'password' => Hash::make('TestingPassword123!'),
            'role' => 'admin',
            'is_active' => $active,
        ]);
    }

    public function test_active_user_can_login(): void
    {
        $user = $this->createAdmin(true);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'TestingPassword123!',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->createAdmin(false);

        // Pastikan akun memang nonaktif di database.
        $this->assertFalse($user->fresh()->isActive());

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'TestingPassword123!',
        ]);

        // Periksa keamanan terlebih dahulu.
        $this->assertGuest();

        // Baru periksa pesan kesalahan.
        $response->assertSessionHasErrors('email');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->createAdmin(true);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'WrongPassword123!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_session_is_terminated(): void
    {
        $user = $this->createAdmin(true);

        // Simulasikan user yang sudah login.
        $this->actingAs($user);

        // Admin menonaktifkan akun tersebut.
        $user->is_active = false;
        $user->save();

        // Request berikutnya harus memutus akses.
        $this->get('/admin/check')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
