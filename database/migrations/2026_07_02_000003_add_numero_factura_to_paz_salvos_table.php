<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->string('numero_factura', 6)->nullable()->after('folio');
        });
    }

    public function down(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropColumn('numero_factura');
        });
    }
};
