<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GET can fail before any operation/version exists. Its recovery must also be durable.
        Schema::table('aaud_auth_access_targets', function (Blueprint $table) {
            $table->integer('delivery_attempts')->default(0);
            $table->timestampTz('next_delivery_at')->nullable();
            $table->string('delivery_error', 48)->nullable();
            $table->uuid('delivery_claim')->nullable();
            $table->timestampTz('delivery_lease_until')->nullable();
            $table->index('next_delivery_at');
        });
        DB::statement('ALTER TABLE aaud_auth_access_targets ADD CONSTRAINT aaud_access_delivery_valid CHECK (delivery_attempts >= 0 AND ((delivery_claim IS NULL) = (delivery_lease_until IS NULL)))');
        $this->allowUnsentCancellation(true);
    }

    public function down(): void
    {
        if (DB::table('aaud_auth_access_targets')->whereNotNull('delivery_claim')->exists()) {
            throw new RuntimeException('Stop delivery and allow leases to recover before rollback.');
        }
        $this->allowUnsentCancellation(false);
        DB::statement('ALTER TABLE aaud_auth_access_targets DROP CONSTRAINT aaud_access_delivery_valid');
        Schema::table('aaud_auth_access_targets', function (Blueprint $table) {
            $table->dropIndex(['next_delivery_at']);
            $table->dropColumn(['delivery_attempts', 'next_delivery_at', 'delivery_error', 'delivery_claim', 'delivery_lease_until']);
        });
    }

    private function allowUnsentCancellation(bool $enable): void
    {
        // Extend the deployed guard without weakening immutable payloads, claim fencing or uncertainty.
        $original = "(OLD.status IN ('failed','acknowledged','uncertain') AND NEW.status='cancelled')";
        $extended = '('.$original." OR (OLD.status='pending' AND NEW.status='cancelled' AND OLD.attempts=0 AND NOT OLD.has_uncertain_attempt AND OLD.receipt IS NULL))";
        $definition = DB::selectOne("SELECT pg_get_functiondef('aaud_access_operation_guard()'::regprocedure) AS definition")->definition;
        $changed = str_replace($enable ? $original : $extended, $enable ? $extended : $original, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected access guard definition. Review before migrating.');
        }
        DB::unprepared($changed);
    }
};
