<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_and_last_login_is_updated(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@gayatri.local',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this->post('/login', [
            'email' => 'admin@gayatri.local',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@gayatri.local',
            'password' => Hash::make('password'),
            'status' => 'inactive',
        ]);

        $this->post('/login', [
            'email' => 'inactive@gayatri.local',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
