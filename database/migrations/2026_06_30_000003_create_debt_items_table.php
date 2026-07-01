<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_query_id')->constrained('debt_queries')->cascadeOnDelete();
            $table->string('period')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status')->nullable();
            $table->boolean('payable')->nullable();
            $table->string('document_type')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('first_expiration_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_items');
    }
};
