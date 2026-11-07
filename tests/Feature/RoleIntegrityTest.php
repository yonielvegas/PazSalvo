<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RoleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_generated_once_and_cannot_be_changed(): void
    {
        $role = Role::create(['name' => 'Revisión Técnica', 'guard_name' => 'web']);
        $this->assertSame('revision_tecnica', $role->code);
        $role->update(['name' => 'Otro nombre']);
        $this->assertSame('revision_tecnica', $role->fresh()->code);
        $this->expectException(\Throwable::class);
        DB::table('roles')->where('id', $role->id)->update(['code' => 'otro']);
    }

    public function test_model_rejects_multiple_roles_without_detaching_the_previous_role(): void
    {
        $a = Role::create(['name' => 'operador', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'consulta', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($a);
        try {
            $user->syncRoles([$a, $b]);
            $this->fail('Multiple roles must be rejected');
        } catch (ValidationException) {
            $this->assertSame([$a->id], $user->fresh()->roles->modelKeys());
        }
    }

    public function test_inactive_role_cannot_be_assigned(): void
    {
        $role = Role::create(['name' => 'inactive', 'guard_name' => 'web', 'is_active' => false]);
        $this->expectException(ValidationException::class);
        User::factory()->create()->assignRole($role);
    }

    public function test_assign_role_replaces_atomically_and_direct_permissions_do_not_bypass_role_requirement(): void
    {
        $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'custom.test', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $this->assertFalse($user->can('custom.test'));
        $user->assignRole($a);
        $this->assertTrue($user->can('custom.test'));
        $version = $user->session_version;
        $user->assignRole($b);
        $this->assertSame([$b->id], $user->fresh()->roles->modelKeys());
        $this->assertGreaterThan($version, $user->fresh()->session_version);
        $this->assertTrue($user->can('custom.test'));
        $user->removeRole($b);
        $this->assertFalse($user->can('custom.test'));
        $this->assertTrue($user->permissions()->whereKey($permission->id)->exists());
    }

    public function test_model_rejects_code_changes_and_normalization_collisions(): void
    {
        $role = Role::create(['name' => 'Revisión', 'guard_name' => 'web']);
        try {
            $role->forceFill(['code' => 'other'])->save();
            $this->fail('Code changes must fail');
        } catch (ValidationException) {
            $this->assertSame('revision', $role->fresh()->code);
        }
        $this->expectException(ValidationException::class);
        Role::create(['name' => 'Revision', 'guard_name' => 'web']);
    }

    public function test_database_rejects_second_user_role_but_allows_other_morph_types(): void
    {
        $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
        foreach ([$a, $b] as $role) {
            DB::table('model_has_roles')->insert(['model_type' => 'OtherModel', 'model_id' => 99, 'role_id' => $role->id]);
        }
        $user = User::factory()->create();
        $user->assignRole($a);
        $this->expectException(QueryException::class);
        DB::table('model_has_roles')->insert(['model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'role_id' => $b->id]);
    }
}
