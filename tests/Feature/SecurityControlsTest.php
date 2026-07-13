<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(string $permissionName): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);

        return $user;
    }

    public function test_authenticated_user_can_access_private_route_when_authorized(): void
    {
        $this->actingAs($this->userWithPermission('consultar paz y salvo'))
            ->get(route('paz-salvo.index'))
            ->assertOk();
    }

    public function test_session_expires_after_idle_timeout(): void
    {
        config(['security.session_idle_timeout_minutes' => 15]);

        $this->actingAs($this->userWithPermission('consultar paz y salvo'))
            ->withSession(['last_authenticated_activity_at' => now()->subMinutes(16)->timestamp])
            ->get(route('paz-salvo.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Su sesión expiró por inactividad. Inicie sesión nuevamente.');

        $this->assertGuest();
    }

    public function test_internal_network_restriction_can_block_private_routes(): void
    {
        config([
            'security.internal_network.enabled' => true,
            'security.internal_network.allowed_ips' => ['10.0.0.0/8'],
        ]);

        $this->actingAs($this->userWithPermission('consultar paz y salvo'))
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->get(route('paz-salvo.index'))
            ->assertStatus(403)
            ->assertInertia(fn ($page) => $page
                ->component('error')
                ->where('message', 'Acceso restringido a la red institucional.')
            );
    }

    public function test_non_admin_cannot_unlock_login_attempts(): void
    {
        $target = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.users.unlock-login-attempts', $target))
            ->assertStatus(403);
    }

    public function test_failed_login_uses_spanish_message_with_remaining_attempts(): void
    {
        $user = User::factory()->create(['email' => 'login-message@example.com']);
        $this->assertSame(0, $user->login_attempts);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'incorrecta',
            ])
            ->assertSessionHasErrors([
                'email' => 'Correo o contraseña incorrectos. Le quedan 2 intentos antes del bloqueo.',
            ]);

        $this->assertFalse(str_contains((string) session('errors')?->first('email'), 'auth.failed'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'login_attempts' => 1,
            'is_login_blocked' => false,
        ]);
    }

    public function test_login_blocks_account_on_third_failed_attempt(): void
    {
        $user = User::factory()->create(['email' => 'lockout-message@example.com']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos. Le quedan 2 intentos antes del bloqueo.']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'login_attempts' => 1, 'is_login_blocked' => false]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos. Le queda 1 intento antes del bloqueo.']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'login_attempts' => 2, 'is_login_blocked' => false]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertSessionHasErrors(['email' => 'Su cuenta fue bloqueada por intentos fallidos. Contacte a un administrador para desbloquearla.']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'login_attempts' => 3, 'is_login_blocked' => true]);
    }

    public function test_blocked_user_cannot_login_even_with_correct_password(): void
    {
        $user = User::factory()->create([
            'email' => 'blocked-login@example.com',
            'login_attempts' => 3,
            'is_login_blocked' => true,
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Su cuenta fue bloqueada por intentos fallidos. Contacte a un administrador para desbloquearla.']);

        $this->assertGuest();
    }

    public function test_successful_login_resets_login_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-attempts@example.com',
            'login_attempts' => 2,
            'is_login_blocked' => false,
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'login_attempts' => 0, 'is_login_blocked' => false]);
    }

    public function test_admin_can_unlock_login_attempts_without_activating_user(): void
    {
        $admin = $this->userWithPermission('administrar usuarios');
        $target = User::factory()->create([
            'email' => 'locked@example.com',
            'is_active' => false,
            'login_attempts' => 3,
            'is_login_blocked' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.unlock-login-attempts', $target))
            ->assertRedirect()
            ->assertSessionHas('message', 'Login desbloqueado correctamente.');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => false,
            'login_attempts' => 0,
            'is_login_blocked' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'login.unblocked',
            'actor_user_id' => $admin->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_unlock_login_attempts_without_active_lockout_returns_clear_message(): void
    {
        $admin = $this->userWithPermission('administrar usuarios');
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.unlock-login-attempts', $target))
            ->assertRedirect()
            ->assertSessionHas('message', 'El usuario no tiene bloqueos activos por intentos fallidos.');
    }

    public function test_admin_can_release_active_session_without_changing_active_status(): void
    {
        $admin = $this->userWithPermission('administrar usuarios');
        $target = User::factory()->create(['is_active' => false]);
        DB::table('sessions')->insert([
            'id' => 'session-to-release',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.release-session', $target))
            ->assertRedirect()
            ->assertSessionHas('message', 'Sesión activa liberada correctamente.');

        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-release']);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.session_released',
            'actor_user_id' => $admin->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_inactive_user_cannot_login_even_when_not_login_blocked(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive-login@example.com',
            'is_active' => false,
            'login_attempts' => 0,
            'is_login_blocked' => false,
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos.']);

        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => false,
            'login_attempts' => 0,
            'is_login_blocked' => false,
        ]);
    }
}
