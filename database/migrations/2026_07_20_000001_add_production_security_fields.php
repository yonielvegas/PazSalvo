<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('session_version')->default(0)->after('is_login_blocked');
            $table->timestampTz('password_changed_at')->nullable()->after('session_version');
        });

        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->uuid('generation_request_id')->nullable()->after('verification_token');
            $table->unique('generation_request_id');
            $table->index(['client_id', 'status', 'expires_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->after('metadata');
            $table->string('result', 30)->nullable()->after('request_id');
            $table->index(['actor_user_id', 'created_at']);
            $table->index('request_id');
        });

        DB::statement('UPDATE users SET password_changed_at = COALESCE(updated_at, now()) WHERE password_changed_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_user_id', 'created_at']);
            $table->dropIndex(['request_id']);
            $table->dropColumn(['request_id', 'result']);
        });

        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropUnique(['generation_request_id']);
            $table->dropIndex(['client_id', 'status', 'expires_at']);
            $table->dropColumn('generation_request_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'session_version',
                'password_changed_at',
            ]);
        });
    }
};
