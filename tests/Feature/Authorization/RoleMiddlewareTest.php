<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_admin_page(): void
    {
        $this->get('/admin/check')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_admin_page(): void
    {
        $admin = User::factory()->create();

        $admin->role = 'admin';
        $admin->save();

        $this->actingAs($admin)
            ->get('/admin/check')
            ->assertOk();
    }

    public function test_technician_cannot_access_admin_page(): void
    {
        $technician = User::factory()->create();

        $technician->role = 'teknisi';
        $technician->save();

        $this->actingAs($technician)
            ->get('/admin/check')
            ->assertForbidden();
    }

    public function test_user_without_role_cannot_access_admin_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/check')
            ->assertForbidden();
    }
}
