<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        Permission::firstOrCreate(['name' => 'consultar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo('consultar paz y salvo');

        return $user;
    }

    public function test_successful_login_sets_session_version(): void
    {
        $user = $this->authorizedUser(['email' => 'single-login@example.com', 'password' => Hash::make('password')]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->fresh()->session_version, session('auth_session_version'));
    }

    public function test_second_login_invalidates_previous_session_version(): void
    {
        $user = $this->authorizedUser(['email' => 'second-login@example.com', 'password' => Hash::make('password')]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $firstVersion = (int) session('auth_session_version');
        $user->forceFill(['session_version' => $firstVersion + 1])->save();

        $this->withSession(['auth_session_version' => $firstVersion, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->actingAs($user->fresh())
            ->get(route('paz-salvo.index'))
            ->assertRedirect(route('login'));
    }

    public function test_different_users_do_not_invalidate_each_other(): void
    {
        $first = $this->authorizedUser();
        $second = $this->authorizedUser();

        $this->actingAs($first)
            ->withSession(['auth_session_version' => $first->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get(route('paz-salvo.index'))
            ->assertOk();

        $this->actingAs($second)
            ->withSession(['auth_session_version' => $second->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get(route('paz-salvo.index'))
            ->assertOk();
    }

    public function test_absolute_timeout_logs_user_out(): void
    {
        config(['security.session_absolute_timeout_minutes' => 480]);
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => $user->session_version,
                'authenticated_session_started_at' => now()->subMinutes(481)->timestamp,
                'session_regenerated_at' => now()->timestamp,
            ])
            ->get(route('paz-salvo.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Su sesión expiró por tiempo máximo de uso. Inicie sesión nuevamente.');
    }

    public function test_forced_password_change_revokes_previous_session_version_and_keeps_current_session(): void
    {
        $user = $this->authorizedUser([
            'password' => Hash::make('aaud.123'),
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
        $oldVersion = $user->session_version;

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $oldVersion, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->put(route('password.force-change.update'), [
                'password' => 'NuevaClaveTemporal.123',
                'password_confirmation' => 'NuevaClaveTemporal.123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan($oldVersion, $user->fresh()->session_version);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->actingAs($user->fresh())
            ->withSession(['auth_session_version' => $user->fresh()->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get(route('paz-salvo.index'))
            ->assertOk();
    }
}
