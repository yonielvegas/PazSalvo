<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sso_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('issuer', 255);
            $table->string('subject', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['issuer', 'subject']);
        });
        DB::statement("ALTER TABLE user_sso_identities ADD CONSTRAINT user_sso_identity_valid CHECK (
            issuer <> '' AND issuer !~ '[[:space:]]' AND subject ~ '^[A-Za-z0-9_-]{1,255}$')");
    }

    public function down(): void
    {
        if (Schema::hasTable('user_sso_identities') && DB::table('user_sso_identities')->exists()) {
            throw new RuntimeException('Cannot discard existing SSO identity links during rollback. Unlink them with audit first.');
        }
        Schema::dropIfExists('user_sso_identities');
    }
};
