<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('username', 255)->nullable());
        DB::statement('CREATE UNIQUE INDEX users_username_ci_unique ON users (LOWER(username)) WHERE username IS NOT NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_normalized CHECK (username IS NULL OR
            (username <> '' AND username = LOWER(BTRIM(username)) AND username !~ '[[:cntrl:]]'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_username_normalized');
        DB::statement('DROP INDEX users_username_ci_unique');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('username'));
    }
};
