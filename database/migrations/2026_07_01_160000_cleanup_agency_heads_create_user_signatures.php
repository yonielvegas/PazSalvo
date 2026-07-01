<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('agency_head_audit_logs');

        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_head_id');
        });

        Schema::dropIfExists('agency_heads');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_agency_head');
        });

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

    public function down(): void
    {
        Schema::dropIfExists('user_signatures');

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_agency_head')->default(false)->after('is_active');
        });

        Schema::create('agency_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('signature_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX unique_active_head_per_agency ON agency_heads (agency_id) WHERE is_active = true');

        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->foreignId('agency_head_id')->nullable()->after('agency_authorizer_id')->constrained()->nullOnDelete();
        });

        Schema::create('agency_head_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agency_head_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('old_signature_path')->nullable();
            $table->text('new_signature_path')->nullable();
            $table->boolean('old_is_active')->nullable();
            $table->boolean('new_is_active')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }
};
