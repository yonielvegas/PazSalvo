<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_lockouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('ip_address', 45)->nullable();
            $table->string('throttle_key');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['email', 'unlocked_at', 'expires_at']);
            $table->index('throttle_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_lockouts');
    }
};
