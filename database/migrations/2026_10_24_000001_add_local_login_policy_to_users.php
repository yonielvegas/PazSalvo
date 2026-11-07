<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve every existing account/password. Only future SSO creation opts out.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('local_login_enabled')->default(true);
        });
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_local_login_has_password CHECK (NOT local_login_enabled OR password IS NOT NULL)');
    }

    public function down(): void
    {
        // Never invent passwords or silently enable a local credential during rollback.
        if (DB::table('users')->where('local_login_enabled', false)->orWhereNull('password')->exists()) {
            throw new RuntimeException('SSO-only accounts exist. Review and resolve them administratively before rollback.');
        }
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_local_login_has_password');
        DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('local_login_enabled'));
    }
};
