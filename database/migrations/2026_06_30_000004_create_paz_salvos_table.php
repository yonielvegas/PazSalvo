<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paz_salvos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence_number');
            $table->unsignedSmallInteger('sequence_year');
            $table->string('folio')->unique();
            $table->uuid('verification_token')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('agency_authorizer_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('total_balance', 12, 2)->default(0);
            $table->timestampTz('issued_at')->index();
            $table->timestampTz('expires_at')->index();
            $table->text('pdf_path')->nullable();
            $table->string('status', 30)->default('processing')->index();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->text('generation_error')->nullable();
            $table->timestamps();
            $table->unique(['sequence_year', 'sequence_number']);
            $table->index(['agency_id', 'issued_at']);
            $table->index(['generated_by', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paz_salvos');
    }
};
