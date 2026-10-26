<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateUserStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(
        string $role,
        bool $active = true
    ): User {
        $user = User::factory()->create();
        $user->role = $role;
        $user->is_active = $active;
        $user->save();

        return $user;
    }

    public function test_admin_can_deactivate_technician(): void
    {
        $admin = $this->makeUser('admin');
        $technician = $this->makeUser('teknisi');

        $this->actingAs($admin)
            ->patch(
                route('admin.users.update-status', $technician),
                ['is_active' => '0']
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse(
            $technician->fresh()->isActive()
        );
    }

    public function test_admin_can_activate_user(): void
    {
        $admin = $this->makeUser('admin');
        $technician = $this->makeUser('teknisi', false);

        $this->actingAs($admin)
            ->patch(
                route('admin.users.update-status', $technician),
                ['is_active' => '1']
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue(
            $technician->fresh()->isActive()
        );
    }

    public function test_guest_cannot_change_status(): void
    {
        $technician = $this->makeUser('teknisi');

        $this->patch(
            route('admin.users.update-status', $technician),
            ['is_active' => '0']
        )->assertRedirect('/login');

        $this->assertTrue(
            $technician->fresh()->isActive()
        );
    }

    public function test_technician_cannot_change_status(): void
    {
        $technician = $this->makeUser('teknisi');
        $target = $this->makeUser('koordinator');

        $this->actingAs($technician)
            ->patch(
                route('admin.users.update-status', $target),
                ['is_active' => '0']
            )
            ->assertForbidden();

        $this->assertTrue($target->fresh()->isActive());
    }

    public function test_coordinator_cannot_change_status(): void
    {
        $coordinator = $this->makeUser('koordinator');
        $target = $this->makeUser('teknisi');

        $this->actingAs($coordinator)
            ->patch(
                route('admin.users.update-status', $target),
                ['is_active' => '0']
            )
            ->assertForbidden();

        $this->assertTrue($target->fresh()->isActive());
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->patch(
                route('admin.users.update-status', $admin),
                ['is_active' => '0']
            )
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_invalid_status_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('teknisi');

        $this->actingAs($admin)
            ->patch(
                route('admin.users.update-status', $target),
                ['is_active' => 'invalid']
            )
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($target->fresh()->isActive());
    }

    public function test_admin_can_deactivate_another_admin(): void
    {
        $admin = $this->makeUser('admin');
        $otherAdmin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->patch(
                route('admin.users.update-status', $otherAdmin),
                ['is_active' => '0']
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($otherAdmin->fresh()->isActive());
        $this->assertTrue($admin->fresh()->isActive());
    }
}
