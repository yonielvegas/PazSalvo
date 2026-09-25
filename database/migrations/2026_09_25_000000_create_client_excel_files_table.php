<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_excel_files', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('path')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_excel_files');
    }
};
