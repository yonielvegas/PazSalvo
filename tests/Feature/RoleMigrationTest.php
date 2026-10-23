<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_roles_are_backfilled_without_changing_ids_or_names_and_rollback_is_safe(): void
    {
        $this->seed(MasterDataSeeder::class);
        $custom = Role::create(['name' => 'Revisión Técnica', 'guard_name' => 'web']);
        $before = DB::table('roles')->orderBy('id')->pluck('name', 'id')->all();
        $migration = require database_path('migrations/2026_10_19_000001_add_stable_role_codes.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('roles', 'code'));
        $migration->up();
        $this->assertSame($before, DB::table('roles')->orderBy('id')->pluck('name', 'id')->all());
        foreach (Role::RESERVED_NAMES as $name) {
            $this->assertDatabaseHas('roles', ['name' => $name, 'code' => $name]);
        }
        $this->assertSame('revision_tecnica', $custom->fresh()->code);
    }

    public function test_backfill_collision_stops_without_changing_roles(): void
    {
        $migration = require database_path('migrations/2026_10_19_000001_add_stable_role_codes.php');
        $migration->down();
        DB::table('roles')->insert([
            ['name' => 'Revisión', 'guard_name' => 'web'],
            ['name' => 'Revision', 'guard_name' => 'web'],
        ]);
        try {
            DB::transaction(fn () => $migration->up());
            $this->fail('Collision must stop the migration');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('collision', $exception->getMessage());
        }
        $this->assertDatabaseCount('roles', 2);
        $this->assertFalse(Schema::hasColumn('roles', 'code'));
    }

    public function test_existing_multiple_roles_stop_index_migration_and_preserve_assignments(): void
    {
        $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_10_19_000002_enforce_one_role_per_user.php');
        $migration->down();
        foreach ([$a, $b] as $role) {
            DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_id' => $user->id, 'model_type' => $user->getMorphClass()]);
        }
        try {
            DB::transaction(fn () => $migration->up());
            $this->fail('Conflicting assignments must stop migration');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string) $user->id, $exception->getMessage());
        }
        $this->assertSame(2, $user->roles()->count());
    }

    public function test_code_uniqueness_is_scoped_to_guard_and_invalid_codes_are_rejected_by_postgres(): void
    {
        Role::create(['name' => 'a', 'code' => 'shared', 'guard_name' => 'web']);
        Role::create(['name' => 'b', 'code' => 'shared', 'guard_name' => 'api']);
        $this->assertDatabaseCount('roles', 2);
        $this->expectException(QueryException::class);
        DB::table('roles')->insert(['name' => 'invalid', 'guard_name' => 'web', 'code' => 'INVALID']);
    }

    public function test_partial_index_includes_configured_user_alias_and_legacy_class(): void
    {
        $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_10_19_000002_enforce_one_role_per_user.php');
        $migration->down();
        $previous = Relation::morphMap();
        try {
            Relation::morphMap(['paz_user' => User::class]);
            $migration->up();
            DB::table('model_has_roles')->insert(['role_id' => $a->id, 'model_id' => $user->id, 'model_type' => 'paz_user']);
            try {
                DB::transaction(fn () => DB::table('model_has_roles')->insert(['role_id' => $b->id, 'model_id' => $user->id, 'model_type' => User::class]));
                $this->fail('A legacy morph class cannot bypass the user alias restriction');
            } catch (QueryException $exception) {
                $this->assertSame('23505', $exception->getCode());
            }
        } finally {
            Relation::morphMap($previous, false);
        }
    }
}
