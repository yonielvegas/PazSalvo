<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleRequiredForLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_with_role_can_log_in(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'operador', 'guard_name' => 'web']));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/paz-salvos/consultar');

        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->fresh()->session_version, session('auth_session_version'));
    }

    public function test_active_user_without_role_cannot_log_in_or_create_authenticated_session(): void
    {
        $user = User::factory()->create(['login_attempts' => 2]);
        $sessionVersion = $user->session_version;

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => User::NO_ROLE_LOGIN_MESSAGE]);

        $this->assertGuest();
        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
        $this->assertNull(session('auth_session_version'));
        $this->assertNull(session('authenticated_session_started_at'));
        $this->assertSame($sessionVersion, $user->fresh()->session_version);
        $this->assertSame(2, $user->fresh()->login_attempts);
        $this->get(route('login'))->assertOk();
    }

    public function test_user_who_loses_all_roles_is_logged_out_on_private_route(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'operador', 'guard_name' => 'web']));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $user->syncRoles([]);

        $this->get(route('paz-salvo.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', User::NO_ROLE_LOGIN_MESSAGE);

        $this->assertGuest();
        $this->assertFalse(Auth::check());
        $this->assertNull(session('auth_session_version'));
        $this->get(route('login'))->assertOk();
    }
}
