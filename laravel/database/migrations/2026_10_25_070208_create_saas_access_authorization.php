<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_capabilities', function (Blueprint $t): void {
            $t->string('code', 100)->primary();
            $t->string('description', 255);
        });
        DB::table('saas_capabilities')->insert(['code' => 'saas.access.manage', 'description' => 'Administrar asignaciones de facultades SaaS; no concede acceso funcional a catálogos.']);
        Schema::create('saas_audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->uuid('operation_id')->unique();
            $t->string('actor_type', 10);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('actor_label', 100)->nullable();
            $t->foreignId('affected_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('capability_code', 100)->nullable();
            $t->foreign('capability_code')->references('code')->on('saas_capabilities')->restrictOnDelete();
            $t->string('action', 100);
            $t->string('source', 20);
            $t->string('result', 20);
            $t->text('justification');
            $t->jsonb('old_values')->nullable();
            $t->jsonb('new_values')->nullable();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['affected_user_id', 'id']);
        });
        Schema::create('saas_capability_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('capability_code', 100);
            $t->foreign('capability_code')->references('code')->on('saas_capabilities')->restrictOnDelete();
            $t->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('granted_at', 6);
            $t->text('grant_reason');
            $t->foreignId('grant_audit_id')->unique()->constrained('saas_audit_logs')->restrictOnDelete();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('revoked_at', 6)->nullable();
            $t->text('revocation_reason')->nullable();
            $t->foreignId('revocation_audit_id')->nullable()->unique()->constrained('saas_audit_logs')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->index(['user_id', 'id']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX saas_one_active_assignment ON saas_capability_assignments(user_id,capability_code) WHERE revoked_at IS NULL;
CREATE UNIQUE INDEX saas_single_bootstrap ON saas_audit_logs(source) WHERE source='bootstrap';
ALTER TABLE saas_audit_logs ALTER COLUMN created_at SET DEFAULT statement_timestamp();
ALTER TABLE saas_capabilities ADD CONSTRAINT saas_capability_namespace CHECK(code ~ '^saas\.[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$' AND length(btrim(description))>0);
ALTER TABLE saas_audit_logs ADD CONSTRAINT saas_audit_shape CHECK (
 (actor_type='system' AND actor_id IS NULL AND actor_label IS NOT NULL AND actor_label='SaasAccessBootstrapSeeder' AND source='bootstrap'
 OR actor_type='user' AND actor_id IS NOT NULL AND actor_label IS NULL AND source='service')
 AND result IN ('succeeded','denied','failed') AND length(btrim(justification))>0);
ALTER TABLE saas_capability_assignments ADD CONSTRAINT saas_assignment_shape CHECK (
 length(btrim(grant_reason))>0 AND ((revision=1 AND revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL AND revocation_audit_id IS NULL)
 OR (revision=2 AND revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revocation_audit_id IS NOT NULL AND revocation_reason IS NOT NULL AND length(btrim(revocation_reason))>0 AND revoked_at>=granted_at)));
CREATE OR REPLACE FUNCTION lock_saas_access() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM pg_advisory_xact_lock(182045,1); RETURN NULL; END $$;
CREATE TRIGGER lock_saas_assignments BEFORE INSERT OR UPDATE OR DELETE ON saas_capability_assignments FOR EACH STATEMENT EXECUTE FUNCTION lock_saas_access();
CREATE TRIGGER lock_saas_user_status BEFORE UPDATE OF is_active ON users FOR EACH STATEMENT EXECUTE FUNCTION lock_saas_access();
CREATE TRIGGER lock_saas_user_deletion BEFORE DELETE ON users FOR EACH STATEMENT EXECUTE FUNCTION lock_saas_access();
CREATE OR REPLACE FUNCTION protect_saas_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'SaaS definitions and audit history are immutable' USING ERRCODE='23514'; END $$;
CREATE TRIGGER protect_saas_audit BEFORE UPDATE OR DELETE ON saas_audit_logs FOR EACH ROW EXECUTE FUNCTION protect_saas_append_only();
CREATE TRIGGER protect_saas_capability BEFORE UPDATE OR DELETE ON saas_capabilities FOR EACH ROW EXECUTE FUNCTION protect_saas_append_only();
CREATE OR REPLACE FUNCTION saas_user_can_manage(actor bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM users u JOIN saas_capability_assignments a ON a.user_id=u.id WHERE u.id=actor AND u.is_active AND a.capability_code='saas.access.manage' AND a.revoked_at IS NULL);
$$;
CREATE OR REPLACE FUNCTION protect_last_saas_user() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.is_active AND (TG_OP='DELETE' OR NOT NEW.is_active) AND EXISTS(SELECT 1 FROM saas_capability_assignments WHERE user_id=OLD.id AND capability_code='saas.access.manage' AND revoked_at IS NULL)
 AND NOT EXISTS(SELECT 1 FROM users u JOIN saas_capability_assignments a ON a.user_id=u.id WHERE u.id<>OLD.id AND u.is_active AND a.capability_code='saas.access.manage' AND a.revoked_at IS NULL)
 THEN RAISE EXCEPTION 'The last active SaaS access manager must be preserved' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER protect_last_saas_user BEFORE UPDATE OF is_active OR DELETE ON users FOR EACH ROW EXECUTE FUNCTION protect_last_saas_user();
CREATE OR REPLACE FUNCTION protect_saas_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE audit_row saas_audit_logs; decision_actor bigint;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SaaS assignment history cannot be deleted' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.revision<>1 THEN RAISE EXCEPTION 'New assignment must be active' USING ERRCODE='23514'; END IF;
  SELECT * INTO audit_row FROM saas_audit_logs WHERE id=NEW.grant_audit_id;
  IF audit_row.action IS DISTINCT FROM 'saas.access.granted' OR audit_row.affected_user_id IS DISTINCT FROM NEW.user_id OR audit_row.capability_code IS DISTINCT FROM NEW.capability_code OR audit_row.actor_id IS DISTINCT FROM NEW.granted_by OR audit_row.result IS DISTINCT FROM 'succeeded' OR audit_row.justification IS DISTINCT FROM NEW.grant_reason OR audit_row.created_at IS DISTINCT FROM NEW.granted_at
  THEN RAISE EXCEPTION 'Matching grant audit evidence required' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.user_id AND is_active) THEN RAISE EXCEPTION 'Active target required' USING ERRCODE='23514'; END IF;
  IF NEW.granted_by IS NULL THEN
   IF audit_row.source<>'bootstrap' OR NEW.capability_code<>'saas.access.manage' OR EXISTS(SELECT 1 FROM saas_capability_assignments)
   THEN RAISE EXCEPTION 'Bootstrap can grant only the first access manager' USING ERRCODE='23514'; END IF;
  ELSIF NEW.granted_by=NEW.user_id OR NOT saas_user_can_manage(NEW.granted_by) THEN
   RAISE EXCEPTION 'Independent authorized SaaS grant actor required' USING ERRCODE='23514';
  END IF;
 ELSE
  IF OLD.revoked_at IS NOT NULL OR NEW.revision<>2 OR NEW IS DISTINCT FROM jsonb_populate_record(OLD,jsonb_build_object('revoked_by',NEW.revoked_by,'revoked_at',NEW.revoked_at,'revocation_reason',NEW.revocation_reason,'revocation_audit_id',NEW.revocation_audit_id,'revision',NEW.revision))
  THEN RAISE EXCEPTION 'Only one immutable revocation transition is allowed' USING ERRCODE='23514'; END IF;
  SELECT * INTO audit_row FROM saas_audit_logs WHERE id=NEW.revocation_audit_id;
  IF audit_row.action IS DISTINCT FROM 'saas.access.revoked' OR audit_row.affected_user_id IS DISTINCT FROM NEW.user_id OR audit_row.capability_code IS DISTINCT FROM NEW.capability_code OR audit_row.actor_id IS DISTINCT FROM NEW.revoked_by OR audit_row.result IS DISTINCT FROM 'succeeded' OR audit_row.justification IS DISTINCT FROM NEW.revocation_reason OR audit_row.created_at IS DISTINCT FROM NEW.revoked_at OR NOT saas_user_can_manage(NEW.revoked_by)
  THEN RAISE EXCEPTION 'Matching authorized revocation audit required' USING ERRCODE='23514'; END IF;
  IF NEW.capability_code='saas.access.manage' AND NOT EXISTS(SELECT 1 FROM users u JOIN saas_capability_assignments a ON a.user_id=u.id WHERE u.is_active AND a.capability_code='saas.access.manage' AND a.revoked_at IS NULL AND a.id<>OLD.id)
  THEN RAISE EXCEPTION 'The last active SaaS access manager must be preserved' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_saas_assignment BEFORE INSERT OR UPDATE OR DELETE ON saas_capability_assignments FOR EACH ROW EXECUTE FUNCTION protect_saas_assignment();
SQL);
    }

    public function down(): void
    {
        if (DB::table('saas_capability_assignments')->exists() || DB::table('saas_audit_logs')->exists()) {
            throw new RuntimeException('SaaS access history exists; explicit restoration strategy required.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS protect_last_saas_user ON users; DROP TRIGGER IF EXISTS lock_saas_user_status ON users; DROP TRIGGER IF EXISTS lock_saas_user_deletion ON users;');
        }
        Schema::dropIfExists('saas_capability_assignments');
        Schema::dropIfExists('saas_audit_logs');
        Schema::dropIfExists('saas_capabilities');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION protect_saas_assignment(); DROP FUNCTION protect_last_saas_user(); DROP FUNCTION saas_user_can_manage(bigint); DROP FUNCTION protect_saas_append_only(); DROP FUNCTION lock_saas_access();');
        }
    }
};
