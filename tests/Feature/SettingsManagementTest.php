<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\AgencyCatalog;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\ProductionBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    public function test_production_bootstrap_is_non_destructive_and_idempotent(): void
    {
        $legacy = Agency::create(['name' => 'PH Multiplaza', 'address' => 'Dirección existente', 'is_active' => false]);
        $custom = Permission::create(['name' => 'custom.production', 'guard_name' => 'web']);
        Role::findByName('admin')->givePermissionTo($custom);
        $seeder = app(ProductionBootstrapSeeder::class);
        $seeder->run();

        $admin = User::where('email', 'itadmin@aaud.gob.pa')->firstOrFail();
        $this->assertSame('IT Admin', $admin->name);
        $this->assertSame('$2y$12$t9Vr4UdoVG5Pjuq/mk.U8.vK/TwJI3DUZBDs3vWQCt/LylOzFVZ.K', $admin->password);
        $this->assertSame(12, password_get_info($admin->password)['options']['cost']);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertSame(AgencyCatalog::IT_ADMIN_AGENCY_CODE, $admin->agency->code);
        $this->assertTrue($admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertFalse($admin->is_login_blocked);
        $this->assertFalse($admin->must_change_password);
        $this->assertSame(0, $admin->login_attempts);
        $this->assertSame(0, $admin->session_version);
        $this->assertSame($legacy->id, $admin->agency_id);
        $this->assertSame('Dirección existente', $legacy->fresh()->address);
        $this->assertFalse($legacy->fresh()->is_active);

        $existingPassword = Hash::make('existing-test-password');
        DB::table('users')->where('id', $admin->id)->update(['password' => $existingPassword, 'session_version' => 9]);
        Role::findByName('admin')->forceFill(['is_active' => false])->save();
        $seeder->run();
        $this->assertSame(5, Agency::count());
        $this->assertSame(1, User::where('email', 'itadmin@aaud.gob.pa')->count());
        $this->assertSame(1, Role::where('name', 'admin')->count());
        $this->assertSame(22, Permission::count());
        $this->assertSame($existingPassword, $admin->fresh()->password);
        $this->assertTrue(Hash::check('existing-test-password', $admin->fresh()->password));
        $this->assertSame(9, $admin->fresh()->session_version);
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('custom.production'));
        $this->assertTrue(filter_var(Role::findByName('admin')->is_active, FILTER_VALIDATE_BOOLEAN));
        foreach (MasterDataSeeder::SETTINGS_PERMISSIONS as $name) {
            $this->assertTrue(Role::findByName('admin')->hasPermissionTo($name));
        }
    }

    public function test_settings_and_agency_actions_enforce_independent_permissions_and_keep_history(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('operador');
        $this->actingAs($actor)->get('/settings')->assertForbidden();
        $this->actingAs($actor)->get('/settings/agencies')->assertForbidden();
        $this->actingAs($actor)->post('/settings/agencies', ['code' => 'E2E', 'name' => 'Prueba'])->assertForbidden();
        $actor->givePermissionTo('settings.view', 'settings.agencies.view');
        $this->actingAs($actor)->get('/settings')->assertOk();
        $this->actingAs($actor)->get('/settings/agencies')->assertOk();
        $this->actingAs($actor)->post('/settings/agencies', ['code' => 'E2E', 'name' => 'Prueba'])->assertForbidden();
        $actor->givePermissionTo('settings.agencies.create');
        $this->actingAs($actor)->post('/settings/agencies', ['code' => 'E2E', 'name' => 'Prueba'])->assertRedirect();
        $agency = Agency::where('code', 'E2E')->firstOrFail();
        $this->assertTrue($agency->is_active);
        $this->actingAs($actor)->put("/settings/agencies/{$agency->id}", ['code' => 'E2E', 'name' => 'Editada'])->assertForbidden();
        $actor->givePermissionTo('settings.agencies.update', 'settings.agencies.disable');
        $this->actingAs($actor)->put("/settings/agencies/{$agency->id}", ['code' => 'E2E', 'name' => 'Editada'])->assertRedirect();
        $this->actingAs($actor)->patch("/settings/agencies/{$agency->id}/toggle")->assertRedirect();
        $this->assertFalse($agency->fresh()->is_active);
        $this->actingAs($actor)->patch("/settings/agencies/{$agency->id}/toggle")->assertRedirect();
        $this->assertTrue($agency->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'agency.created', 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'agency.updated', 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'agency.disabled', 'actor_user_id' => $actor->id]);
    }

    public function test_role_permissions_and_admin_protection(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $this->actingAs($actor)->post('/settings/roles', [
            'name' => 'editor_custom',
            'permissions' => ['settings.view', 'settings.agencies.view'],
        ])->assertRedirect();
        $role = Role::findByName('editor_custom');
        $this->assertEqualsCanonicalizing(['settings.view', 'settings.agencies.view'], $role->permissions->pluck('name')->all());
        $this->actingAs($actor)->put("/settings/roles/{$role->id}/permissions", ['permissions' => ['settings.roles.view']])->assertRedirect();
        $this->assertSame(['settings.roles.view'], $role->fresh()->permissions->pluck('name')->all());
        $this->actingAs($actor)->put("/settings/roles/{$role->id}", ['name' => 'editor_renamed'])->assertRedirect();
        $this->assertSame('editor_renamed', $role->fresh()->name);
        $assigned = User::factory()->create();
        $assigned->assignRole($role);
        $this->actingAs($actor)->patch("/settings/roles/{$role->id}/toggle")->assertSessionHasErrors('role');
        $this->assertTrue(filter_var($role->fresh()->is_active, FILTER_VALIDATE_BOOLEAN));
        $assigned->syncRoles(['operador']);
        $this->actingAs($actor)->patch("/settings/roles/{$role->id}/toggle")->assertRedirect();
        $this->assertFalse(filter_var($role->fresh()->is_active, FILTER_VALIDATE_BOOLEAN));
        $this->actingAs($actor)->patch("/settings/roles/{$role->id}/toggle")->assertRedirect();
        $this->assertTrue(filter_var($role->fresh()->is_active, FILTER_VALIDATE_BOOLEAN));
        $adminRole = Role::findByName('admin');
        $this->actingAs($actor)->put("/settings/roles/{$adminRole->id}/permissions", ['permissions' => []])->assertSessionHasErrors('permissions');
        $this->actingAs($actor)->patch("/settings/roles/{$adminRole->id}/toggle")->assertSessionHasErrors('role');
        $this->assertTrue($adminRole->fresh()->hasPermissionTo('settings.roles.permissions'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.created', 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.permissions_updated', 'actor_user_id' => $actor->id]);
    }

    public function test_partial_settings_permissions_do_not_allow_writes(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('operador');
        $actor->givePermissionTo('settings.agencies.view');
        $this->actingAs($actor)->get('/settings/agencies')->assertForbidden();
        $actor->givePermissionTo('settings.view', 'settings.agencies.view', 'settings.roles.view');
        $agency = Agency::factory()->create();
        $role = Role::findByName('operador');

        $this->actingAs($actor)->get('/settings')->assertOk();
        $this->actingAs($actor)->get('/settings/agencies')->assertOk();
        $this->actingAs($actor)->get('/settings/roles')->assertOk();
        $this->actingAs($actor)->post('/settings/agencies', ['code' => 'DENIED', 'name' => 'Denied'])->assertForbidden();
        $this->actingAs($actor)->put("/settings/agencies/{$agency->id}", ['code' => 'DENIED', 'name' => 'Denied'])->assertForbidden();
        $this->actingAs($actor)->patch("/settings/agencies/{$agency->id}/toggle")->assertForbidden();
        $this->actingAs($actor)->post('/settings/roles', ['name' => 'denied_role'])->assertForbidden();
        $this->actingAs($actor)->put("/settings/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();
        $actor->givePermissionTo('settings.roles.create');
        $this->actingAs($actor)->post('/settings/roles', ['name' => 'unsafe_role', 'permissions' => ['settings.view']])->assertForbidden();
        $this->actingAs($actor)->post('/settings/roles', ['name' => 'empty_role', 'permissions' => []])->assertRedirect();
    }

    public function test_last_active_admin_cannot_be_demoted_or_deleted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $operator = User::factory()->create();
        $operator->assignRole('operador');
        $operator->givePermissionTo('administrar usuarios');

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'agency_id' => $admin->agency_id,
            'role' => 'operador',
        ])->assertSessionHasErrors('role');
        $this->actingAs($operator)->delete("/admin/users/{$admin->id}")->assertSessionHasErrors('user');
        $this->actingAs($operator)->patch("/admin/users/{$admin->id}/toggle")->assertSessionHasErrors('user');
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertTrue($admin->fresh()->is_active);
    }
}
