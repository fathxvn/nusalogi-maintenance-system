<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateUserRoleTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_admin_can_update_user_role(): void
    {
        $admin = $this->makeUser('admin');
        $technician = $this->makeUser('teknisi');

        $this->actingAs($admin)
            ->patch(route('admin.users.update-role', $technician), [
                'role' => 'koordinator',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame(
            'koordinator',
            $technician->fresh()->role
        );
    }

    public function test_guest_cannot_update_role(): void
    {
        $technician = $this->makeUser('teknisi');

        $this->patch(
            route('admin.users.update-role', $technician),
            ['role' => 'admin']
        )->assertRedirect('/login');

        $this->assertSame(
            'teknisi',
            $technician->fresh()->role
        );
    }

    public function test_technician_cannot_update_role(): void
    {
        $technician = $this->makeUser('teknisi');
        $target = $this->makeUser('koordinator');

        $this->actingAs($technician)
            ->patch(route('admin.users.update-role', $target), [
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->assertSame(
            'koordinator',
            $target->fresh()->role
        );
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('teknisi');

        $this->actingAs($admin)
            ->patch(route('admin.users.update-role', $target), [
                'role' => 'superadmin',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(
            'teknisi',
            $target->fresh()->role
        );
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->patch(route('admin.users.update-role', $admin), [
                'role' => 'teknisi',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(
            'admin',
            $admin->fresh()->role
        );
    }

    public function test_admin_can_open_edit_form(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('teknisi');

        $this->withoutVite();

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertViewHas('user')
            ->assertViewHas('roles', User::ROLES);
    }
}
