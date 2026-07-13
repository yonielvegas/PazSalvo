<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->foreignId('general_admin_signature_id')->nullable()->after('user_signature_id')->constrained('general_admin_signatures')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('general_admin_signature_id');
        });
    }
};
