<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_authorizer_id');
            $table->foreignId('user_signature_id')->nullable()->after('agency_id')->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('authorizer_audit_logs');
        Schema::dropIfExists('agency_authorizers');
        Schema::dropIfExists('authorizers');
    }

    public function down(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_signature_id');
            $table->foreignId('agency_authorizer_id')->nullable()->after('agency_id')->constrained('agency_authorizers')->nullOnDelete();
        });

        Schema::create('authorizers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agency_authorizers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorizer_id')->constrained()->restrictOnDelete();
            $table->text('signature_path');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('authorizer_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorizer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_authorizer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('old_full_name')->nullable();
            $table->string('new_full_name')->nullable();
            $table->string('old_position')->nullable();
            $table->string('new_position')->nullable();
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
