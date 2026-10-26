<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('saas_capabilities')->insert(['code' => 'saas.clinical_reasons.classify_impact', 'description' => 'Declarar y corregir explícitamente el impacto global, sin autoridad tenant.']);
        Schema::create('global_clinical_reason_impacts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('reason_id');
            $t->unsignedBigInteger('version_id');
            $t->unsignedBigInteger('from_version_id')->nullable();
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->string('classification', 20);
            $t->text('justification');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['version_id', 'revision']);
            $t->unique(['version_id', 'id']);
            $t->unique(['reason_id', 'id']);
            $t->foreign(['reason_id', 'version_id'])->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['reason_id', 'from_version_id'])->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['version_id', 'previous_id'])->references(['version_id', 'id'])->on('global_clinical_reason_impacts')->restrictOnDelete();
        });
        Schema::table('saas_audit_logs', function (Blueprint $t): void {
            $t->foreignId('global_impact_declaration_id')->nullable()->unique()->constrained('global_clinical_reason_impacts')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE global_clinical_reason_impacts ALTER COLUMN created_at SET DEFAULT statement_timestamp();
ALTER TABLE global_clinical_reason_impacts ADD CONSTRAINT global_impact_shape CHECK(revision>0 AND classification IN ('unclassified','non_restrictive','restrictive') AND length(btrim(justification))>0);
ALTER TABLE saas_audit_logs ADD CONSTRAINT global_impact_audit_shape CHECK(
 (global_impact_declaration_id IS NULL AND action<>'saas.clinical_reasons.impact_declared') OR
 (global_impact_declaration_id IS NOT NULL AND action='saas.clinical_reasons.impact_declared' AND actor_type='user' AND affected_user_id IS NULL AND capability_code IS NOT NULL AND capability_code='saas.clinical_reasons.classify_impact' AND result='succeeded' AND global_reason_id IS NULL));
CREATE OR REPLACE FUNCTION serialize_global_impact() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE preceding global_clinical_reason_impacts; target global_clinical_reason_versions; origin bigint;
BEGIN
 PERFORM pg_advisory_xact_lock(182045,1);
 SELECT * INTO target FROM global_clinical_reason_versions WHERE id=NEW.version_id FOR UPDATE;
 SELECT id INTO origin FROM global_clinical_reason_versions WHERE reason_id=NEW.reason_id AND number=target.number-1;
 SELECT * INTO preceding FROM global_clinical_reason_impacts WHERE version_id=NEW.version_id ORDER BY revision DESC LIMIT 1;
 IF NEW.revision IS DISTINCT FROM coalesce(preceding.revision,0)+1 OR NEW.previous_id IS DISTINCT FROM preceding.id OR NEW.from_version_id IS DISTINCT FROM origin
 THEN RAISE EXCEPTION 'Impact requires exact predecessor and transition' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER serialize_global_impact BEFORE INSERT ON global_clinical_reason_impacts FOR EACH ROW EXECUTE FUNCTION serialize_global_impact();
CREATE TRIGGER protect_global_impact BEFORE UPDATE OR DELETE ON global_clinical_reason_impacts FOR EACH ROW EXECUTE FUNCTION protect_clinical_reason_record();
CREATE OR REPLACE FUNCTION verify_global_impact_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE declaration global_clinical_reason_impacts;
BEGIN
 IF NEW.global_impact_declaration_id IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO declaration FROM global_clinical_reason_impacts WHERE id=NEW.global_impact_declaration_id;
 IF declaration.created_by IS DISTINCT FROM NEW.actor_id OR declaration.justification IS DISTINCT FROM NEW.justification
 OR NOT EXISTS(SELECT 1 FROM users u JOIN saas_capability_assignments a ON a.user_id=u.id WHERE u.id=NEW.actor_id AND u.is_active AND a.capability_code='saas.clinical_reasons.classify_impact' AND a.revoked_at IS NULL)
 THEN RAISE EXCEPTION 'Authorized matching impact audit required' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verify_global_impact_audit BEFORE INSERT ON saas_audit_logs FOR EACH ROW EXECUTE FUNCTION verify_global_impact_audit();
CREATE OR REPLACE FUNCTION require_global_impact_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM saas_audit_logs WHERE global_impact_declaration_id=NEW.id)
 THEN RAISE EXCEPTION 'Committed impact requires audit evidence' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER require_global_impact_audit AFTER INSERT ON global_clinical_reason_impacts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_global_impact_audit();
SQL);
    }

    public function down(): void
    {
        if (DB::table('global_clinical_reason_impacts')->exists() || DB::table('saas_capability_assignments')->where('capability_code', 'saas.clinical_reasons.classify_impact')->exists()) {
            throw new RuntimeException('Impact history exists; explicit restoration required.');
        }
        DB::unprepared('DROP TRIGGER verify_global_impact_audit ON saas_audit_logs; DROP FUNCTION verify_global_impact_audit(); ALTER TABLE saas_audit_logs DROP CONSTRAINT global_impact_audit_shape;');
        Schema::table('saas_audit_logs', function (Blueprint $t): void {
            $t->dropForeign(['global_impact_declaration_id']);
            $t->dropUnique(['global_impact_declaration_id']);
            $t->dropColumn('global_impact_declaration_id');
        });
        Schema::drop('global_clinical_reason_impacts');
        DB::unprepared('DROP FUNCTION require_global_impact_audit(); DROP FUNCTION serialize_global_impact(); DROP TRIGGER protect_saas_capability ON saas_capabilities;');
        DB::table('saas_capabilities')->where('code', 'saas.clinical_reasons.classify_impact')->delete();
        DB::statement('CREATE TRIGGER protect_saas_capability BEFORE UPDATE OR DELETE ON saas_capabilities FOR EACH ROW EXECUTE FUNCTION protect_saas_append_only()');
    }
};
