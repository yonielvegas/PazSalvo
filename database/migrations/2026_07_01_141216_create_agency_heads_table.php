<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_heads');
    }
};
