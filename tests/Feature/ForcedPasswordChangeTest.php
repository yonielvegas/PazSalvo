<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'administrar usuarios', 'guard_name' => 'web']);
        $user->givePermissionTo('administrar usuarios');

        return $user;
    }

    private function operator(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        Permission::firstOrCreate(['name' => 'consultar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo('consultar paz y salvo');

        return $user;
    }

    public function test_admin_users_page_exposes_reset_password_context(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['name' => 'A Administrador'])->save();
        $target = User::factory()->create(['name' => 'B Usuario Reset', 'must_change_password' => false]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/users/index')
                ->where('temporary_password', 'aaud.123')
                ->has('users', fn ($users) => $users
                    ->where('1.id', $target->id)
                    ->where('1.must_change_password', false)
                    ->etc()
                )
            );
    }

    public function test_user_without_permission_cannot_reset_passwords(): void
    {
        $target = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.users.reset-password', $target))
            ->assertForbidden();
    }

    public function test_admin_reset_unlocks_user_sets_temporary_password_and_revokes_sessions(): void
    {
        $admin = $this->admin();
        $target = $this->operator([
            'password' => Hash::make('ClaveAnterior.123'),
            'login_attempts' => 3,
            'is_login_blocked' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
            'session_version' => 4,
        ]);

        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.reset-password', $target))
            ->assertRedirect()
            ->assertSessionHas('message', 'Contraseña restablecida correctamente.');

        $target->refresh();
        $this->assertFalse($target->is_login_blocked);
        $this->assertSame(0, $target->login_attempts);
        $this->assertTrue($target->must_change_password);
        $this->assertNull($target->password_changed_at);
        $this->assertSame($admin->id, $target->password_reset_by);
        $this->assertTrue(Hash::check('aaud.123', $target->password));
        $this->assertSame(5, $target->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);

        $audit = AuditLog::where('event', 'user.password_reset')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame($target->id, $audit->subject_id);
        $this->assertTrue($audit->metadata['was_blocked']);
        $this->assertArrayNotHasKey('password', $audit->metadata);
    }

    public function test_reset_non_blocked_user_reaches_same_expected_state(): void
    {
        $admin = $this->admin();
        $target = $this->operator([
            'password' => Hash::make('ClaveAnterior.123'),
            'login_attempts' => 1,
            'is_login_blocked' => false,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.reset-password', $target))
            ->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->is_login_blocked);
        $this->assertSame(0, $target->login_attempts);
        $this->assertTrue($target->must_change_password);
        $this->assertTrue(Hash::check('aaud.123', $target->password));
    }

    public function test_old_password_stops_working_and_temporary_password_redirects_to_forced_change(): void
    {
        $admin = $this->admin();
        $target = $this->operator([
            'email' => 'reset-login@example.com',
            'password' => Hash::make('ClaveAnterior.123'),
        ]);

        $this->actingAs($admin)->patch(route('admin.users.reset-password', $target));
        auth()->logout();

        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'ClaveAnterior.123'])
            ->assertSessionHasErrors();

        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'aaud.123'])
            ->assertRedirect(route('password.force-change'));
    }

    public function test_user_must_change_password_cannot_access_private_modules_but_can_logout(): void
    {
        $user = $this->operator(['must_change_password' => true]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get(route('paz-salvo.index'))
            ->assertRedirect(route('password.force-change'));

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->post(route('logout'))
            ->assertRedirect('/');
    }

    #[DataProvider('invalidPasswords')]
    public function test_forced_password_validation_rejects_invalid_passwords(string $password): void
    {
        $user = $this->operator([
            'password' => Hash::make('aaud.123'),
            'must_change_password' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->put(route('password.force-change.update'), [
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasErrors('password');
    }

    public static function invalidPasswords(): array
    {
        return [
            'too short' => ['Aa1!'],
            'no uppercase' => ['clave.123'],
            'no lowercase' => ['CLAVE.123'],
            'no number' => ['ClaveTemporal!'],
            'no symbol' => ['ClaveTemporal123'],
            'temporary password' => ['aaud.123'],
        ];
    }

    public function test_forced_password_validation_rejects_confirmation_mismatch(): void
    {
        $user = $this->operator(['must_change_password' => true]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->put(route('password.force-change.update'), [
                'password' => 'NuevaClave.123',
                'password_confirmation' => 'OtraClave.123',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_valid_forced_password_change_clears_flag_and_keeps_current_session_valid(): void
    {
        $user = $this->operator([
            'password' => Hash::make('aaud.123'),
            'must_change_password' => true,
            'password_reset_at' => now(),
            'password_reset_by' => $this->admin()->id,
        ]);
        $oldVersion = $user->session_version;

        DB::table('sessions')->insert([
            'id' => 'previous-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $oldVersion, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->put(route('password.force-change.update'), [
                'password' => 'NuevaClave.123',
                'password_confirmation' => 'NuevaClave.123',
            ])
            ->assertRedirect(route('paz-salvo.index'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NuevaClave.123', $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertNull($user->password_reset_at);
        $this->assertNull($user->password_reset_by);
        $this->assertSame($oldVersion + 1, $user->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'previous-session']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.forced_password_changed',
            'actor_user_id' => $user->id,
            'subject_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get(route('paz-salvo.index'))
            ->assertOk();
    }

    public function test_voluntary_password_routes_are_not_available(): void
    {
        $user = $this->operator();

        $this->actingAs($user)->get('/perfil/password')->assertNotFound();
        $this->actingAs($user)->put('/user/password')->assertNotFound();
    }

    public function test_forced_change_route_has_no_redirect_loop(): void
    {
        $user = $this->operator(['must_change_password' => true]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get(route('password.force-change'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/forced-password-change'));

        $user->forceFill(['must_change_password' => false])->save();

        $this->actingAs($user->fresh())
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get(route('password.force-change'))
            ->assertRedirect(route('paz-salvo.index'));
    }

    public function test_repeated_reset_keeps_consistent_state(): void
    {
        $admin = $this->admin();
        $target = $this->operator(['session_version' => 1]);

        $this->actingAs($admin)->patch(route('admin.users.reset-password', $target))->assertRedirect();
        $this->actingAs($admin)->patch(route('admin.users.reset-password', $target))->assertRedirect();

        $target->refresh();
        $this->assertTrue($target->must_change_password);
        $this->assertSame(0, $target->login_attempts);
        $this->assertFalse($target->is_login_blocked);
        $this->assertSame(3, $target->session_version);
        $this->assertTrue(Hash::check('aaud.123', $target->password));
    }
}
