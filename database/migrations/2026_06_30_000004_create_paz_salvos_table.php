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
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('client_number', 30)->index();
            $table->string('holder_name');
            $table->string('rate')->nullable();
            $table->string('district')->nullable();
            $table->string('corregimiento')->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->text('full_address');
            $table->decimal('total_balance', 12, 2)->default(0);
            $table->decimal('expired_balance', 12, 2)->default(0);
            $table->decimal('non_expired_balance', 12, 2)->default(0);
            $table->timestampTz('issued_at')->index();
            $table->timestampTz('expires_at')->index();
            $table->string('authorized_by_name')->default('Vielsa Vergara');
            $table->string('agency_name_snapshot');
            $table->string('generated_by_name_snapshot');
            $table->text('legal_text');
            $table->text('xlsx_path')->nullable();
            $table->text('pdf_path')->nullable();
            $table->text('qr_path')->nullable();
            $table->string('status', 30)->default('processing')->index();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->text('generation_error')->nullable();
            $table->jsonb('raw_widergy_response')->nullable();
            $table->jsonb('certificate_snapshot')->nullable();
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
