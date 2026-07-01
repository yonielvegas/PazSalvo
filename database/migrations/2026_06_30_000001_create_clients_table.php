<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_number', 30)->unique();
            $table->string('holder_name')->nullable();
            $table->string('district')->nullable();
            $table->string('corregimiento')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('rate')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
