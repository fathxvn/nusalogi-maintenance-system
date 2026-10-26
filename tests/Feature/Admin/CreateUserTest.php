<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->role = 'admin';
        $admin->save();

        return $admin;
    }

    public function test_guest_cannot_create_user(): void
    {
        $this->get('/admin/users/create')
            ->assertRedirect('/login');

        $this->post('/admin/users', [])
            ->assertRedirect('/login');
    }

    public function test_admin_can_create_technician(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Teknisi Testing',
                'email' => 'teknisi@example.test',
                'role' => 'teknisi',
                'password' => 'PasswordTesting123!',
                'password_confirmation' => 'PasswordTesting123!',
            ]);

        $response->assertRedirect(
            route('admin.users.index')
        );

        $user = User::where(
            'email',
            'teknisi@example.test'
        )->firstOrFail();

        $this->assertSame('teknisi', $user->role);
        $this->assertTrue($user->is_active);

        $this->assertTrue(Hash::check(
            'PasswordTesting123!',
            $user->password
        ));
    }

    public function test_admin_can_create_coordinator(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Koordinator Testing',
                'email' => 'koordinator@example.test',
                'role' => 'koordinator',
                'password' => 'PasswordTesting123!',
                'password_confirmation' => 'PasswordTesting123!',
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'koordinator@example.test')->firstOrFail();

        $this->assertSame('koordinator', $user->role);
        $this->assertTrue($user->isActive());
        $this->assertTrue(Hash::check('PasswordTesting123!', $user->password));
    }

    public function test_create_form_only_offers_mvp_roles(): void
    {
        $admin = $this->createAdmin();

        $this->withoutVite();

        $this->actingAs($admin)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertViewHas('roles', ['admin', 'koordinator', 'teknisi'])
            ->assertSee('value="admin"', false)
            ->assertSee('value="koordinator"', false)
            ->assertSee('value="teknisi"', false)
            ->assertDontSee('value="finance"', false)
            ->assertDontSee('value="manager"', false)
            ->assertDontSee('value="Koordinator"', false);
    }

    #[TestWith(['teknisi'])]
    #[TestWith(['koordinator'])]
    #[TestWith([null])]
    public function test_non_admin_cannot_create_user(?string $role): void
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        $this->actingAs($user)
            ->post('/admin/users', [
                'name' => 'Unauthorized User',
                'email' => 'unauthorized@example.test',
                'role' => 'admin',
                'password' => 'PasswordTesting123!',
                'password_confirmation' => 'PasswordTesting123!',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'email' => 'unauthorized@example.test',
        ]);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->createAdmin();

        User::factory()->create([
            'email' => 'existing@example.test',
        ]);

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Duplicate User',
                'email' => 'existing@example.test',
                'role' => 'teknisi',
                'password' => 'PasswordTesting123!',
                'password_confirmation' => 'PasswordTesting123!',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(
            1,
            User::where(
                'email',
                'existing@example.test'
            )->count()
        );
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Invalid Role',
                'email' => 'invalid@example.test',
                'role' => 'superadmin',
                'password' => 'PasswordTesting123!',
                'password_confirmation' => 'PasswordTesting123!',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'email' => 'invalid@example.test',
        ]);
    }

    #[TestWith(['finance'])]
    #[TestWith(['manager'])]
    #[TestWith(['Koordinator'])]
    public function test_legacy_role_is_rejected(string $role): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Legacy Role',
                'email' => 'legacy@example.test',
                'role' => $role,
                'password' => 'PasswordTesting123!',
                'password_confirmation' => 'PasswordTesting123!',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'email' => 'legacy@example.test',
        ]);
    }

    public function test_password_confirmation_is_required(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Wrong Confirmation',
                'email' => 'wrong@example.test',
                'role' => 'teknisi',
                'password' => 'PasswordTesting123!',
                'password_confirmation' => 'DifferentPassword123!',
            ])
            ->assertSessionHasErrors('password');
    }
}
