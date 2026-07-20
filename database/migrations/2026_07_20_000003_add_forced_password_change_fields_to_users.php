<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->index()->after('password_changed_at');
            }

            if (! Schema::hasColumn('users', 'password_reset_at')) {
                $table->timestampTz('password_reset_at')->nullable()->after('must_change_password');
            }

            if (! Schema::hasColumn('users', 'password_reset_by')) {
                $table->foreignId('password_reset_by')
                    ->nullable()
                    ->after('password_reset_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'password_reset_by')) {
                $table->dropConstrainedForeignId('password_reset_by');
            }

            if (Schema::hasColumn('users', 'password_reset_at')) {
                $table->dropColumn('password_reset_at');
            }

            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });
    }
};
