<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->foreignId('agency_head_id')->nullable()->after('agency_authorizer_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_head_id');
        });
    }
};
