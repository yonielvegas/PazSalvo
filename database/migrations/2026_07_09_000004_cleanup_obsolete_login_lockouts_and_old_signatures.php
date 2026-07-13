<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paz_salvos')) {
            Schema::table('paz_salvos', function (Blueprint $table) {
                if (Schema::hasColumn('paz_salvos', 'user_signature_id')) {
                    $table->dropConstrainedForeignId('user_signature_id');
                }

                if (Schema::hasColumn('paz_salvos', 'agency_head_id')) {
                    $table->dropConstrainedForeignId('agency_head_id');
                }

                if (Schema::hasColumn('paz_salvos', 'agency_authorizer_id')) {
                    $table->dropConstrainedForeignId('agency_authorizer_id');
                }
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_agency_head')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_agency_head');
            });
        }

        Schema::dropIfExists('login_lockouts');
        Schema::dropIfExists('user_signatures');
        Schema::dropIfExists('agency_head_audit_logs');
        Schema::dropIfExists('agency_heads');
        Schema::dropIfExists('authorizer_audit_logs');
        Schema::dropIfExists('agency_authorizers');
        Schema::dropIfExists('authorizers');
    }

    public function down(): void
    {
        if (! Schema::hasTable('login_lockouts')) {
            Schema::create('login_lockouts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('email');
                $table->string('ip_address', 45)->nullable();
                $table->string('throttle_key');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('unlocked_at')->nullable();
                $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['email', 'unlocked_at', 'expires_at']);
                $table->index('throttle_key');
            });
        }

        if (! Schema::hasTable('user_signatures')) {
            Schema::create('user_signatures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->text('signature_path');
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('deactivated_at')->nullable();
                $table->text('deactivation_reason')->nullable();
                $table->timestamps();
            });

            DB::statement('CREATE UNIQUE INDEX unique_active_signature_per_agency ON user_signatures (agency_id) WHERE is_active = true');
        }

        if (Schema::hasTable('paz_salvos') && ! Schema::hasColumn('paz_salvos', 'user_signature_id')) {
            Schema::table('paz_salvos', function (Blueprint $table) {
                $table->foreignId('user_signature_id')->nullable()->after('agency_id')->constrained()->nullOnDelete();
            });
        }
    }
};
