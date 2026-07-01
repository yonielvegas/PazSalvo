<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('client_number', 30)->index();
            $table->string('job_id')->nullable()->index();
            $table->text('job_url')->nullable();
            $table->string('status', 30)->index();
            $table->decimal('total_balance', 12, 2)->default(0);
            $table->decimal('expired_balance', 12, 2)->default(0);
            $table->decimal('non_expired_balance', 12, 2)->default(0);
            $table->string('external_holder_name')->nullable();
            $table->text('external_address')->nullable();
            $table->string('external_city')->nullable();
            $table->string('external_rate')->nullable();
            $table->date('next_expiration_on')->nullable();
            $table->jsonb('raw_response');
            $table->timestampTz('queried_at')->index();
            $table->timestamps();
            $table->index(['client_number', 'queried_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_queries');
    }
};
