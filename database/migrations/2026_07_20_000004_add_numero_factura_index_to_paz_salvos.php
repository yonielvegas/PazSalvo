<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->index('numero_factura', 'paz_salvos_numero_factura_index');
        });
    }

    public function down(): void
    {
        Schema::table('paz_salvos', function (Blueprint $table) {
            $table->dropIndex('paz_salvos_numero_factura_index');
        });
    }
};
