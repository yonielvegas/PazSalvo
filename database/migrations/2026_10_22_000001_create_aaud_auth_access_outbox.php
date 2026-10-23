<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aaud_auth_access_states', function (Blueprint $t) {
            $t->id();
            $t->string('application_code', 32)->default('pazysalvo');
            $t->string('issuer', 255);
            $t->string('subject', 255);
            $t->bigInteger('last_remote_version')->nullable();
            $t->bigInteger('last_reserved_version')->nullable();
            $t->boolean('remote_absent')->nullable();
            $t->jsonb('remote_snapshot')->nullable();
            $t->timestampTz('remote_verified_at')->nullable();
            $t->timestampsTz();
            $t->unique(['application_code', 'issuer', 'subject'], 'aaud_access_identity_unique');
        });
        Schema::create('aaud_auth_access_targets', function (Blueprint $t) {
            $t->string('application_user_id', 128)->primary();
            $t->jsonb('desired')->nullable();
            $t->jsonb('published_identity')->nullable();
            $t->jsonb('required_revocation')->nullable();
            $t->bigInteger('originated_by'); // Historical ID, deliberately no user FK/cascade.
            $t->timestampsTz();
        });
        Schema::create('aaud_auth_access_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('state_id')->constrained('aaud_auth_access_states')->restrictOnDelete();
            $t->string('application_user_id', 128);
            $t->foreign('application_user_id')->references('application_user_id')->on('aaud_auth_access_targets')->restrictOnDelete();
            $t->bigInteger('version');
            $t->uuid('operation_id')->unique();
            $t->jsonb('payload');
            $t->char('payload_hash', 64);
            $t->string('status', 16)->default('pending');
            $t->integer('attempts')->default(0);
            $t->boolean('has_uncertain_attempt')->default(false);
            $t->bigInteger('originated_by');
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->uuid('claim_id')->nullable();
            $t->timestampTz('lease_expires_at')->nullable();
            $t->string('last_error_code', 48)->nullable();
            $t->jsonb('receipt')->nullable();
            $t->timestampTz('confirmed_at')->nullable();
            $t->string('resolution_reference', 160)->nullable();
            $t->bigInteger('resolved_by')->nullable();
            $t->bigInteger('resolved_remote_version')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['state_id', 'version']);
            $t->index(['status', 'next_attempt_at']);
            $t->index(['status', 'lease_expires_at']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE aaud_auth_access_states ADD CHECK (application_code = 'pazysalvo' AND issuer <> '' AND subject ~ '^[A-Za-z0-9_-]{1,255}$'
 AND (last_remote_version IS NULL OR last_remote_version BETWEEN 0 AND 9007199254740991)
 AND (last_reserved_version IS NULL OR last_reserved_version BETWEEN 1 AND 9007199254740991)
 AND ((last_remote_version IS NULL AND remote_verified_at IS NULL AND remote_absent IS NULL AND remote_snapshot IS NULL)
 OR (last_remote_version IS NOT NULL AND remote_verified_at IS NOT NULL AND remote_absent IS NOT NULL AND remote_snapshot IS NOT NULL))
 AND (remote_absent IS DISTINCT FROM true OR last_remote_version = 0));
ALTER TABLE aaud_auth_access_targets ADD CHECK (application_user_id ~ '^[1-9][0-9]{0,127}$');
CREATE UNIQUE INDEX aaud_access_one_identity_unresolved ON aaud_auth_access_operations(state_id) WHERE status NOT IN ('confirmed','cancelled');
CREATE UNIQUE INDEX aaud_access_one_owner_unresolved ON aaud_auth_access_operations(application_user_id) WHERE status NOT IN ('confirmed','cancelled');
ALTER TABLE aaud_auth_access_operations ADD CHECK (version BETWEEN 1 AND 9007199254740991 AND attempts >= 0
 AND status IN ('pending','processing','retryable','uncertain','acknowledged','confirmed','failed','cancelled')
 AND payload_hash ~ '^[a-f0-9]{64}$' AND jsonb_typeof(payload)='object'
 AND payload->>'application_user_id'=application_user_id AND payload->'version'=to_jsonb(version) AND payload->>'operation_id'=operation_id::text
 AND (payload - ARRAY['issuer','subject','application_user_id','is_active','roles','version','operation_id'])='{}'::jsonb
 AND jsonb_exists_all(payload,ARRAY['issuer','subject','application_user_id','is_active','roles','version','operation_id'])
 AND jsonb_typeof(payload->'is_active')='boolean' AND jsonb_typeof(payload->'roles')='array'
 AND jsonb_array_length(payload->'roles')=CASE WHEN (payload->>'is_active')::boolean THEN 1 ELSE 0 END
 AND (NOT (payload->>'is_active')::boolean OR (jsonb_typeof(payload->'roles'->0)='string' AND (payload->'roles'->>0) COLLATE "C" ~ '^[a-z0-9][a-z0-9_.:-]{0,79}$'))
 AND ((status='processing')=(claim_id IS NOT NULL AND lease_expires_at IS NOT NULL))
 AND (status='processing' OR (claim_id IS NULL AND lease_expires_at IS NULL))
 AND (status NOT IN ('acknowledged','confirmed') OR coalesce((receipt IS NOT NULL
  AND receipt->>'status' IN ('applied','duplicate')
  AND receipt->'data'->>'application_code'='pazysalvo'
  AND ((receipt->'data') - ARRAY['application_code','confirmed_at'])=payload
  AND (receipt->>'current_version')::bigint>=version
  AND receipt->'historical'=to_jsonb(status='acknowledged')),false))
 AND ((status='confirmed')=(confirmed_at IS NOT NULL))
 AND (status<>'uncertain' OR has_uncertain_attempt)
 AND ((status='cancelled')=(resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND resolution_reference IS NOT NULL AND resolved_remote_version IS NOT NULL)));
CREATE OR REPLACE FUNCTION aaud_access_operation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s aaud_auth_access_states; calculated text; clock timestamptz := clock_timestamp();
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Access operations cannot be deleted' USING ERRCODE='23514'; END IF;
 SELECT * INTO STRICT s FROM aaud_auth_access_states WHERE id=NEW.state_id;
 IF NEW.payload->>'issuer' IS DISTINCT FROM s.issuer OR NEW.payload->>'subject' IS DISTINCT FROM s.subject THEN
  RAISE EXCEPTION 'Access identity mismatch' USING ERRCODE='23514'; END IF;
 calculated := encode(sha256(convert_to(NEW.payload::text,'UTF8')),'hex');
 IF NEW.status='processing' AND (NEW.lease_expires_at<=clock OR NEW.last_attempt_at IS NULL) THEN RAISE EXCEPTION 'Invalid lease' USING ERRCODE='23514'; END IF;
 IF NEW.status='cancelled' AND (NEW.resolved_remote_version IS DISTINCT FROM s.last_remote_version OR ((OLD.has_uncertain_attempt OR OLD.status='acknowledged') AND NEW.resolved_remote_version<OLD.version)) THEN RAISE EXCEPTION 'Reconciliation required' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
  IF s.last_remote_version IS NULL OR NEW.version<>greatest(s.last_remote_version,coalesce(s.last_reserved_version,0))+1 THEN RAISE EXCEPTION 'Verified monotonic version required' USING ERRCODE='23514'; END IF;
  IF NEW.status<>'pending' OR NEW.attempts<>0 THEN RAISE EXCEPTION 'Operation must start pending' USING ERRCODE='23514'; END IF;
  IF NEW.payload_hash IS NOT NULL AND NEW.payload_hash<>calculated THEN RAISE EXCEPTION 'Invalid integrity hash' USING ERRCODE='23514'; END IF;
  NEW.payload_hash := calculated;
 ELSE
  IF ROW(NEW.id,NEW.state_id,NEW.application_user_id,NEW.version,NEW.operation_id,NEW.payload,NEW.payload_hash,NEW.originated_by,NEW.created_at)
   IS DISTINCT FROM ROW(OLD.id,OLD.state_id,OLD.application_user_id,OLD.version,OLD.operation_id,OLD.payload,OLD.payload_hash,OLD.originated_by,OLD.created_at)
   OR NEW.payload_hash<>calculated THEN RAISE EXCEPTION 'Access operation immutable' USING ERRCODE='23514'; END IF;
  IF (OLD.status IN ('confirmed','cancelled') AND NEW IS DISTINCT FROM OLD)
   OR (NEW.status<>OLD.status AND NOT (
    (OLD.status IN ('pending','retryable','uncertain') AND NEW.status='processing') OR
    (OLD.status='processing' AND NEW.status IN ('confirmed','acknowledged','retryable','uncertain','failed')) OR
    (OLD.status IN ('failed','acknowledged','uncertain') AND NEW.status='cancelled')))
   OR NEW.attempts<>OLD.attempts+(CASE WHEN NEW.status='processing' AND OLD.status<>'processing' THEN 1 ELSE 0 END)
   THEN RAISE EXCEPTION 'Invalid access transition' USING ERRCODE='23514'; END IF;
  IF OLD.receipt IS NOT NULL AND NEW.receipt IS DISTINCT FROM OLD.receipt THEN RAISE EXCEPTION 'Receipt immutable' USING ERRCODE='23514'; END IF;
  IF OLD.has_uncertain_attempt AND NOT NEW.has_uncertain_attempt THEN RAISE EXCEPTION 'Uncertainty cannot be erased' USING ERRCODE='23514'; END IF;
  IF OLD.status='processing' AND NEW.status='processing' AND ROW(NEW.claim_id,NEW.lease_expires_at) IS DISTINCT FROM ROW(OLD.claim_id,OLD.lease_expires_at)
   THEN RAISE EXCEPTION 'Claim cannot be replaced' USING ERRCODE='23514'; END IF;
  IF OLD.status='processing' AND NEW.status IN ('confirmed','acknowledged','failed','retryable') AND OLD.lease_expires_at<=clock
   THEN RAISE EXCEPTION 'Expired claim' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER aaud_access_operation_guard BEFORE INSERT OR UPDATE OR DELETE ON aaud_auth_access_operations FOR EACH ROW EXECUTE FUNCTION aaud_access_operation_guard();
CREATE OR REPLACE FUNCTION aaud_access_state_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Durable access state cannot be deleted' USING ERRCODE='23514'; END IF;
 IF ROW(NEW.id,NEW.application_code,NEW.issuer,NEW.subject,NEW.created_at) IS DISTINCT FROM ROW(OLD.id,OLD.application_code,OLD.issuer,OLD.subject,OLD.created_at)
  OR (OLD.last_remote_version IS NOT NULL AND (NEW.last_remote_version IS NULL OR NEW.last_remote_version<OLD.last_remote_version))
  OR (OLD.last_reserved_version IS NOT NULL AND (NEW.last_reserved_version IS NULL OR NEW.last_reserved_version<OLD.last_reserved_version))
  THEN RAISE EXCEPTION 'Access identity/version cannot regress' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER aaud_access_state_guard BEFORE UPDATE OR DELETE ON aaud_auth_access_states FOR EACH ROW EXECUTE FUNCTION aaud_access_state_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('aaud_auth_access_operations')->exists() || DB::table('aaud_auth_access_targets')->exists() || DB::table('aaud_auth_access_states')->exists()) {
            throw new RuntimeException('Cannot discard durable access state. Explicit archival/review required.');
        }
        Schema::dropIfExists('aaud_auth_access_operations');
        DB::statement('DROP FUNCTION IF EXISTS aaud_access_operation_guard()');
        Schema::dropIfExists('aaud_auth_access_targets');
        Schema::dropIfExists('aaud_auth_access_states');
        DB::statement('DROP FUNCTION IF EXISTS aaud_access_state_guard()');
    }
};
