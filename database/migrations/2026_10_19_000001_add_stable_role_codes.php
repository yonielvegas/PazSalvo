<?php

use App\Support\RoleCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE roles IN ACCESS EXCLUSIVE MODE');
        $codes = [];
        $backfill = [];
        foreach (DB::table('roles')->orderBy('id')->get() as $role) {
            $code = RoleCode::fromName($role->name);
            $key = $role->guard_name.'|'.$code;
            if (isset($codes[$key])) {
                throw new RuntimeException("Role code collision: role IDs {$codes[$key]} and {$role->id}, guard {$role->guard_name}, code {$code}. No data changed.");
            }
            $codes[$key] = $role->id;
            $backfill[$role->id] = $code;
        }
        Schema::table('roles', fn (Blueprint $table) => $table->string('code', 80)->nullable());
        foreach ($backfill as $id => $code) {
            DB::table('roles')->where('id', $id)->update(['code' => $code]);
        }
        DB::statement('ALTER TABLE roles ALTER COLUMN code SET NOT NULL');
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_code_format CHECK (code COLLATE "C" ~ \'^[a-z0-9][a-z0-9_.:-]{0,79}$\')');
        Schema::table('roles', fn (Blueprint $table) => $table->unique(['guard_name', 'code'], 'roles_guard_code_unique'));
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pazsalvo_role_code_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'Role code is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER roles_code_immutable BEFORE UPDATE ON roles
            FOR EACH ROW EXECUTE FUNCTION pazsalvo_role_code_immutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS roles_code_immutable ON roles; DROP FUNCTION IF EXISTS pazsalvo_role_code_immutable();');
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_guard_code_unique');
        });
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_code_format');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('code'));
    }
};
