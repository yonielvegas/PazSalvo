<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aaud_auth_catalog_states', function (Blueprint $table) {
            $table->string('application_code', 32);
            $table->string('resource', 32);
            $table->bigInteger('last_remote_version')->nullable();
            $table->bigInteger('last_reserved_version')->nullable();
            $table->timestampTz('remote_verified_at')->nullable();
            $table->timestampTz('last_confirmed_at')->nullable();
            $table->timestampsTz();
            $table->primary(['application_code', 'resource']);
        });
        DB::statement("ALTER TABLE aaud_auth_catalog_states ADD CONSTRAINT aaud_catalog_state_valid CHECK (
            application_code = 'pazysalvo' AND resource = 'roles'
            AND (last_remote_version IS NULL OR last_remote_version BETWEEN 0 AND 9007199254740991)
            AND (last_reserved_version IS NULL OR last_reserved_version BETWEEN 1 AND 9007199254740991)
            AND ((last_remote_version IS NULL) = (remote_verified_at IS NULL)))");
        DB::table('aaud_auth_catalog_states')->insert([
            'application_code' => 'pazysalvo', 'resource' => 'roles',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::create('aaud_auth_catalog_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('application_code', 32);
            $table->string('resource', 32);
            $table->bigInteger('version');
            $table->uuid('operation_id')->unique();
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->boolean('has_uncertain_attempt')->default(false);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error_code', 48)->nullable();
            $table->uuid('claim_id')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('receipt_status', 16)->nullable();
            $table->bigInteger('receipt_current_version')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->bigInteger('resolved_remote_version')->nullable();
            $table->string('resolution_reference', 160)->nullable();
            // Historical approving user ID; no FK that could change existing user deletion policies.
            $table->bigInteger('resolution_approved_by')->nullable();
            $table->timestampsTz();
            $table->foreign(['application_code', 'resource'], 'aaud_catalog_operation_state_fk')
                ->references(['application_code', 'resource'])->on('aaud_auth_catalog_states')->restrictOnDelete();
            $table->unique(['application_code', 'resource', 'version'], 'aaud_catalog_operation_version_unique');
            $table->index(['status', 'next_attempt_at'], 'aaud_catalog_recovery_index');
            $table->index(['status', 'lease_expires_at'], 'aaud_catalog_lease_index');
        });
        DB::statement("CREATE UNIQUE INDEX aaud_catalog_one_unresolved ON aaud_auth_catalog_operations
            (application_code, resource) WHERE status NOT IN ('confirmed', 'cancelled')");
        DB::statement("ALTER TABLE aaud_auth_catalog_operations ADD CONSTRAINT aaud_catalog_operation_valid CHECK (
            version BETWEEN 1 AND 9007199254740991 AND attempts >= 0
            AND status IN ('pending','processing','confirmed','retryable','uncertain','failed','cancelled')
            AND jsonb_typeof(payload) = 'object'
            AND jsonb_exists_all(payload, ARRAY['version','operation_id','roles'])
            AND (payload - ARRAY['version','operation_id','roles']) = '{}'::jsonb
            AND jsonb_typeof(payload->'version') = 'number' AND payload->'version' = to_jsonb(version)
            AND jsonb_typeof(payload->'operation_id') = 'string' AND payload->>'operation_id' = operation_id::text
            AND CASE WHEN jsonb_typeof(payload->'roles') = 'array' THEN jsonb_array_length(payload->'roles') BETWEEN 1 AND 1000 ELSE false END
            AND payload_hash ~ '^[a-f0-9]{64}$'
            AND ((status = 'processing' AND claim_id IS NOT NULL AND lease_expires_at IS NOT NULL)
                 OR (status <> 'processing' AND claim_id IS NULL AND lease_expires_at IS NULL))
            AND ((status = 'confirmed' AND confirmed_at IS NOT NULL AND receipt_status IS NOT NULL AND receipt_status IN ('applied','duplicate')
                 AND receipt_current_version IS NOT NULL AND receipt_current_version BETWEEN version AND 9007199254740991)
                 OR (status <> 'confirmed' AND confirmed_at IS NULL AND receipt_status IS NULL AND receipt_current_version IS NULL))
            AND (status <> 'uncertain' OR has_uncertain_attempt)
            AND ((status = 'cancelled' AND resolved_at IS NOT NULL AND resolved_remote_version IS NOT NULL
                  AND resolved_remote_version BETWEEN 0 AND 9007199254740991
                  AND resolution_reference IS NOT NULL AND resolution_reference ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,159}$'
                  AND resolution_approved_by IS NOT NULL)
                 OR (status <> 'cancelled' AND resolved_at IS NULL AND resolved_remote_version IS NULL
                     AND resolution_reference IS NULL AND resolution_approved_by IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION aaud_auth_catalog_operation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE calculated text; checked_at timestamptz := clock_timestamp();
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Catalog operations cannot be deleted' USING ERRCODE = '23514';
    END IF;
    calculated := encode(sha256(convert_to(NEW.payload::text, 'UTF8')), 'hex');
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'pending' OR NEW.attempts <> 0 THEN
            RAISE EXCEPTION 'Catalog operations must start pending' USING ERRCODE = '23514';
        END IF;
        IF NEW.payload_hash IS NOT NULL AND NEW.payload_hash <> calculated THEN
            RAISE EXCEPTION 'Invalid catalog integrity hash' USING ERRCODE = '23514';
        END IF;
        NEW.payload_hash := calculated;
    ELSE
        IF ROW(NEW.id, NEW.application_code, NEW.resource, NEW.version, NEW.operation_id, NEW.payload, NEW.payload_hash, NEW.created_at)
            IS DISTINCT FROM ROW(OLD.id, OLD.application_code, OLD.resource, OLD.version, OLD.operation_id, OLD.payload, OLD.payload_hash, OLD.created_at)
            OR NEW.payload_hash <> calculated THEN
            RAISE EXCEPTION 'Catalog operation identity is immutable' USING ERRCODE = '23514';
        END IF;
        IF (NEW.status <> OLD.status AND NOT (
            (OLD.status IN ('pending','retryable','uncertain') AND NEW.status = 'processing') OR
            (OLD.status = 'processing' AND NEW.status IN ('confirmed','retryable','uncertain','failed')) OR
            (OLD.status IN ('failed','uncertain') AND NEW.status = 'cancelled')))
            OR (OLD.status IN ('confirmed','cancelled') AND NEW IS DISTINCT FROM OLD)
            OR (OLD.status = 'failed' AND NEW.status <> 'cancelled' AND NEW IS DISTINCT FROM OLD)
            OR NEW.attempts <> OLD.attempts + (CASE WHEN NEW.status = 'processing' AND OLD.status <> 'processing' THEN 1 ELSE 0 END) THEN
            RAISE EXCEPTION 'Invalid catalog operation transition' USING ERRCODE = '23514';
        END IF;
        IF (NEW.has_uncertain_attempt IS DISTINCT FROM OLD.has_uncertain_attempt
            AND NOT (OLD.status = 'processing' AND NEW.status = 'uncertain' AND NEW.has_uncertain_attempt))
            OR (OLD.status = 'processing' AND NEW.status = 'processing'
                AND ROW(NEW.claim_id, NEW.lease_expires_at) IS DISTINCT FROM ROW(OLD.claim_id, OLD.lease_expires_at)) THEN
            RAISE EXCEPTION 'Claim and uncertainty history are protected' USING ERRCODE = '23514';
        END IF;
        IF OLD.status <> 'processing' AND NEW.status = 'processing' AND NEW.lease_expires_at <= checked_at THEN
            RAISE EXCEPTION 'Claim lease must be in the future' USING ERRCODE = '23514';
        END IF;
        IF OLD.status = 'processing' AND NEW.status <> 'processing' AND (
            (OLD.lease_expires_at <= checked_at AND (NEW.status <> 'uncertain' OR NEW.last_error_code <> 'lease_expired'))
            OR (OLD.lease_expires_at > checked_at AND NEW.last_error_code = 'lease_expired')) THEN
            RAISE EXCEPTION 'Expired claims require uncertain recovery' USING ERRCODE = '23514';
        END IF;
        IF OLD.status IN ('failed','uncertain') AND NEW.status = 'cancelled'
            AND (OLD.has_uncertain_attempt OR COALESCE(OLD.last_error_code, '') NOT IN (
                'disabled','configuration','invalid_operation','unauthorized','forbidden','version_conflict',
                'idempotency_conflict','conflict','invalid_request','credentials_rejected'))
            AND NEW.resolved_remote_version < OLD.version THEN
            RAISE EXCEPTION 'Cancellation requires a verified remote version fencing uncertain attempts' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER aaud_auth_catalog_operation_guard BEFORE INSERT OR UPDATE OR DELETE
    ON aaud_auth_catalog_operations FOR EACH ROW EXECUTE FUNCTION aaud_auth_catalog_operation_guard();
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('aaud_auth_catalog_operations') && DB::table('aaud_auth_catalog_operations')->exists()) {
            throw new RuntimeException('Cannot roll back a populated catalog outbox. Preserve and reconcile its durable operations first.');
        }
        Schema::dropIfExists('aaud_auth_catalog_operations');
        Schema::dropIfExists('aaud_auth_catalog_states');
        DB::unprepared('DROP FUNCTION IF EXISTS aaud_auth_catalog_operation_guard()');
    }
};
