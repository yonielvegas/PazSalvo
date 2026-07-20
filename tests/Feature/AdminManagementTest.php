<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_paz_salvos_schema_contains_only_normalized_certificate_fields(): void
    {
        foreach (['xlsx_path', 'qr_path', 'raw_widergy_response', 'certificate_snapshot', 'legal_text', 'generated_by_name_snapshot', 'agency_name_snapshot', 'authorized_by_name', 'full_address', 'expired_balance', 'non_expired_balance', 'user_signature_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('paz_salvos', $column), "Unexpected column: {$column}");
        }

        foreach (['client_id', 'general_admin_signature_id', 'total_balance', 'pdf_path'] as $column) {
            $this->assertTrue(Schema::hasColumn('paz_salvos', $column), "Missing column: {$column}");
        }

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "Unexpected MFA column: {$column}");
        }

        $this->assertFalse(Schema::hasTable('login_lockouts'));
        $this->assertFalse(Schema::hasTable('user_signatures'));
    }

    public function test_admin_pages_require_corresponding_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $permission = Permission::create(['name' => 'administrar usuarios', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        $this->actingAs($user)->get('/admin/users')->assertOk();
    }

    public function test_admin_can_create_edit_and_deactivate_user_and_update_role_permissions(): void
    {
        $agency = Agency::factory()->create();
        $actor = User::factory()->create(['agency_id' => $agency->id]);
        $manageUsers = Permission::create(['name' => 'administrar usuarios', 'guard_name' => 'web']);
        $manageRoles = Permission::create(['name' => 'administrar roles', 'guard_name' => 'web']);
        $consult = Permission::create(['name' => 'consultar paz y salvo', 'guard_name' => 'web']);
        $operator = Role::create(['name' => 'operador', 'guard_name' => 'web']);
        $actor->givePermissionTo([$manageUsers, $manageRoles]);

        $this->actingAs($actor)->post('/admin/users', [
            'name' => 'Usuario Nuevo',
            'email' => 'nuevo@aaud.gob.pa',
            'agency_id' => $agency->id,
            'role' => $operator->name,
        ])->assertRedirect();
        $user = User::where('email', 'nuevo@aaud.gob.pa')->firstOrFail();
        $this->assertTrue($user->hasRole('operador'));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('aaud.123', $user->password));

        $this->actingAs($actor)->put("/admin/users/{$user->id}", [
            'name' => 'Usuario Editado',
            'email' => 'editado@aaud.gob.pa',
            'agency_id' => $agency->id,
            'role' => $operator->name,
        ])->assertRedirect();
        $this->actingAs($actor)->patch("/admin/users/{$user->id}/toggle")->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Usuario Editado', 'email' => 'editado@aaud.gob.pa', 'is_active' => false]);

        $this->actingAs($actor)->put("/admin/roles/{$operator->id}/permissions", ['permissions' => [$consult->name]])->assertRedirect();
        $this->assertTrue($operator->fresh()->hasPermissionTo($consult));
    }

    public function test_users_index_includes_login_and_session_state_fields(): void
    {
        $agency = Agency::factory()->create();
        $actor = User::factory()->create(['agency_id' => $agency->id, 'name' => 'A Administrador']);
        $target = User::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'B Usuario Bloqueado',
            'login_attempts' => 2,
            'is_login_blocked' => true,
        ]);
        $permission = Permission::create(['name' => 'administrar usuarios', 'guard_name' => 'web']);
        $actor->givePermissionTo($permission);

        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($actor)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/users/index')
                ->has('users.1', fn ($user) => $user
                    ->where('id', $target->id)
                    ->where('login_attempts', 2)
                    ->where('is_login_blocked', true)
                    ->where('must_change_password', false)
                    ->where('has_active_session', true)
                    ->where('active_session_count', 1)
                    ->etc()
                )
            );
    }
}
