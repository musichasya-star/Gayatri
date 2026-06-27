<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_user_management(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Manajemen User');
    }

    public function test_admin_cannot_view_user_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_owner_can_create_user(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)
            ->post(route('admin.users.store'), [
                'name' => 'Admin Baru',
                'email' => 'adminbaru@gayatri.local',
                'phone' => '08123456789',
                'role' => 'admin',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'adminbaru@gayatri.local',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
