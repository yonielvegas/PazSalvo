<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sso_identities', fn (Blueprint $table) => $table->jsonb('verified_profile')->nullable());
        DB::statement("ALTER TABLE user_sso_identities ADD CONSTRAINT sso_verified_profile_object CHECK
            (verified_profile IS NULL OR jsonb_typeof(verified_profile) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_sso_identities DROP CONSTRAINT sso_verified_profile_object');
        Schema::table('user_sso_identities', fn (Blueprint $table) => $table->dropColumn('verified_profile'));
    }
};
