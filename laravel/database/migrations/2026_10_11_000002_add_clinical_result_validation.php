<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratories', fn (Blueprint $t) => $t->string('validation_policy', 30)->default('professional_required'));
        Schema::create('validation_professionals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('laboratory_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('professional_name', 150);
            $t->string('profession', 150);
            $t->string('registration_number', 100)->nullable();
            $t->string('status', 20)->default('registered');
            foreach (['authorized', 'revoked'] as $event) {
                $t->timestampTz($event.'_at', 6)->nullable();
                $t->foreignId($event.'_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->foreign(['laboratory_id', $event.'_by'], 'professional_'.$event.'_membership_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
            }
            $t->text('revocation_reason')->nullable();
            $t->unique(['laboratory_id', 'user_id']);
            $t->unique(['laboratory_id', 'id'], 'validation_professional_identity');
            $t->foreign(['laboratory_id', 'user_id'])->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('laboratory_result_validations', function (Blueprint $t) {
            $t->id();
            $owners = ['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id'];
            foreach ($owners as $column) {
                $t->unsignedBigInteger($column);
            }
            $t->unsignedBigInteger('approved_revision');
            $t->unsignedBigInteger('transition_revision');
            foreach (['results_snapshot', 'reference_snapshot', 'context_snapshot'] as $column) {
                $t->jsonb($column);
            }
            $t->string('validation_policy', 30);
            $t->foreignId('validated_by')->constrained('users')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'validated_by'], 'validation_actor_membership_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
            $t->unsignedBigInteger('professional_id')->nullable();
            $t->jsonb('professional_snapshot')->nullable();
            $t->timestampTz('validated_at', 6);
            $t->text('comment')->nullable();
            $t->unique(['laboratory_order_exam_id', 'approved_revision'], 'validation_revision_unique');
            $t->unique(['laboratory_id', 'laboratory_order_exam_id', 'id'], 'validation_identity');
            $t->foreign($owners, 'validation_capture_fk')->references($owners)->on('laboratory_order_result_captures')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'professional_id'], 'validation_professional_fk')->references(['laboratory_id', 'id'])->on('validation_professionals')->restrictOnDelete();
        });
        Schema::create('laboratory_result_rectifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_exam_id');
            $t->unsignedBigInteger('validation_id');
            $t->unsignedBigInteger('from_revision');
            $t->unsignedBigInteger('to_revision');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'requested_by'], 'rectification_actor_membership_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
            $t->timestampTz('requested_at', 6);
            $t->text('reason');
            $t->string('validation_policy', 30);
            $t->unsignedBigInteger('professional_id')->nullable();
            $t->jsonb('professional_snapshot')->nullable();
            $t->unique(['laboratory_order_exam_id', 'from_revision'], 'rectification_revision_unique');
            $t->foreign(['laboratory_id', 'laboratory_order_exam_id', 'validation_id'], 'rectification_validation_fk')->references(['laboratory_id', 'laboratory_order_exam_id', 'id'])->on('laboratory_result_validations')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'professional_id'], 'rectification_professional_fk')->references(['laboratory_id', 'id'])->on('validation_professionals')->restrictOnDelete();
        });
        Schema::table('laboratory_order_result_captures', function (Blueprint $t) {
            $t->unsignedBigInteger('last_validation_id')->nullable();
            $t->foreign(['laboratory_id', 'laboratory_order_exam_id', 'last_validation_id'], 'capture_validation_fk')->references(['laboratory_id', 'laboratory_order_exam_id', 'id'])->on('laboratory_result_validations')->restrictOnDelete();
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE laboratories ADD CONSTRAINT laboratory_validation_policy_check CHECK(validation_policy IN ('professional_required','permission_based'));
ALTER TABLE validation_professionals ADD CONSTRAINT professional_state_check CHECK (
 status IN ('registered','authorized','revoked') AND
 (status <> 'authorized' OR (authorized_at IS NOT NULL AND authorized_by IS NOT NULL AND authorized_by <> user_id)) AND
 (status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND length(btrim(revocation_reason)) > 0)));
ALTER TABLE laboratory_order_result_captures DROP CONSTRAINT result_capture_values_check;
ALTER TABLE laboratory_order_result_captures ADD CONSTRAINT result_capture_values_check CHECK(revision >= 1 AND status IN ('preliminary','validated') AND (status <> 'validated' OR last_validation_id IS NOT NULL));
ALTER TABLE laboratory_result_validations ADD CONSTRAINT validation_values_check CHECK (
 approved_revision >= 1 AND transition_revision = approved_revision + 1 AND jsonb_typeof(results_snapshot) = 'array' AND jsonb_typeof(reference_snapshot) = 'array' AND jsonb_typeof(context_snapshot) = 'object' AND
 ((validation_policy = 'professional_required' AND professional_id IS NOT NULL AND professional_snapshot IS NOT NULL) OR
 (validation_policy = 'permission_based' AND professional_id IS NULL AND professional_snapshot IS NULL)));
ALTER TABLE laboratory_result_rectifications ADD CONSTRAINT rectification_values_check CHECK (
 to_revision = from_revision + 1 AND length(btrim(reason)) > 0 AND
 ((validation_policy = 'professional_required' AND professional_id IS NOT NULL AND professional_snapshot IS NOT NULL) OR
 (validation_policy = 'permission_based' AND professional_id IS NULL AND professional_snapshot IS NULL)));
CREATE OR REPLACE FUNCTION protect_validation_professional() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Professional history cannot be deleted' USING ERRCODE='23514'; END IF;
 IF TG_OP = 'UPDATE' AND (NEW.id,NEW.laboratory_id,NEW.user_id,NEW.professional_name,NEW.profession,NEW.registration_number) IS DISTINCT FROM (OLD.id,OLD.laboratory_id,OLD.user_id,OLD.professional_name,OLD.profession,OLD.registration_number)
 THEN RAISE EXCEPTION 'Professional identity is immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_validation_professional BEFORE UPDATE OR DELETE ON validation_professionals FOR EACH ROW EXECUTE FUNCTION protect_validation_professional();
CREATE OR REPLACE FUNCTION clinical_result_snapshot(target_line bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT coalesce(jsonb_agg(jsonb_build_object('parameter_code',r.parameter_code,'parameter_snapshot',r.parameter_snapshot,'value_type',r.value_type,
    'value',CASE WHEN r.value_type='numeric' THEN trim_scale(r.numeric_value)::text WHEN r.value_type='text' THEN r.text_value ELSE r.option_code END,
    'unit',r.unit,'option_label',r.option_label,'captured_by',r.captured_by,'captured_at',to_char(r.captured_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')) ORDER BY p.position),'[]'::jsonb)
 FROM laboratory_order_results r JOIN laboratory_exam_versions v ON v.id=r.clinical_version_id
 JOIN jsonb_array_elements(v.configuration->'parameters') WITH ORDINALITY p(document,position) ON p.document->>'code'=r.parameter_code
 WHERE r.laboratory_order_exam_id=target_line;
$$;
CREATE OR REPLACE FUNCTION protect_validation_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c laboratory_order_result_captures; expected jsonb; parameters jsonb; actor_id bigint; ref jsonb; parameter jsonb;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Validation and rectification evidence is immutable' USING ERRCODE='23514'; END IF;
 SELECT * INTO c FROM laboratory_order_result_captures WHERE laboratory_order_exam_id = NEW.laboratory_order_exam_id;
 IF TG_TABLE_NAME='laboratory_result_rectifications' THEN actor_id := NEW.requested_by; ELSE actor_id := NEW.validated_by; END IF;
 IF NEW.validation_policy IS DISTINCT FROM (SELECT validation_policy FROM laboratories WHERE id=NEW.laboratory_id)
 THEN RAISE EXCEPTION 'Evidence must preserve current laboratory policy' USING ERRCODE='23514'; END IF;
 IF NEW.validation_policy='professional_required' AND NOT EXISTS (SELECT 1 FROM validation_professionals p WHERE p.id=NEW.professional_id AND p.laboratory_id=NEW.laboratory_id AND p.user_id=actor_id AND p.status='authorized')
 THEN RAISE EXCEPTION 'Current professional authorization required' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME = 'laboratory_result_rectifications' THEN
  IF c.status IS DISTINCT FROM 'validated' OR c.revision IS DISTINCT FROM NEW.from_revision OR c.last_validation_id IS DISTINCT FROM NEW.validation_id THEN RAISE EXCEPTION 'Rectification must derive from current validation' USING ERRCODE='23514'; END IF;
 ELSE
  IF c.status IS DISTINCT FROM 'preliminary' OR c.revision IS DISTINCT FROM NEW.approved_revision THEN RAISE EXCEPTION 'Validation must approve the current preliminary revision' USING ERRCODE='23514'; END IF;
  SELECT configuration->'parameters' INTO parameters FROM laboratory_exam_versions WHERE id = c.clinical_version_id;
  SELECT coalesce(jsonb_agg(jsonb_build_object('parameter_code',r.parameter_code,'parameter_snapshot',r.parameter_snapshot,'value_type',r.value_type,
    'value',CASE WHEN r.value_type='numeric' THEN trim_scale(r.numeric_value)::text WHEN r.value_type='text' THEN r.text_value ELSE r.option_code END,
    'unit',r.unit,'option_label',r.option_label,'captured_by',r.captured_by,'captured_at',to_char(r.captured_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')) ORDER BY p.position),'[]'::jsonb) INTO expected
   FROM laboratory_order_results r JOIN jsonb_array_elements(parameters) WITH ORDINALITY p(document,position) ON p.document->>'code'=r.parameter_code WHERE r.laboratory_order_exam_id=c.laboratory_order_exam_id;
  IF jsonb_array_length(expected) <> jsonb_array_length(parameters) OR NEW.results_snapshot IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Validation snapshot must exactly match complete historical results' USING ERRCODE='23514'; END IF;
  IF jsonb_array_length(NEW.reference_snapshot) <> jsonb_array_length(parameters) OR (SELECT count(DISTINCT x->>'parameter_code') FROM jsonb_array_elements(NEW.reference_snapshot) x) <> jsonb_array_length(parameters) THEN RAISE EXCEPTION 'Reference evidence must cover every parameter' USING ERRCODE='23514'; END IF;
  FOR ref IN SELECT x FROM jsonb_array_elements(NEW.reference_snapshot) x LOOP
   SELECT x INTO parameter FROM jsonb_array_elements(parameters) x WHERE x->>'code'=ref->>'parameter_code';
   IF parameter IS NULL OR ref->>'status' IS NULL OR ref->>'status' NOT IN ('not_configured','not_applicable','resolved') THEN RAISE EXCEPTION 'Unresolved references cannot be validated' USING ERRCODE='23514'; END IF;
   IF ref->>'status'='resolved' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(parameter->'references') x WHERE x->>'key'=ref->>'reference_key' AND x=ref->'reference') THEN RAISE EXCEPTION 'Selected reference must exactly match historical publication' USING ERRCODE='23514'; END IF;
   IF ref->>'status'='not_configured' AND coalesce(jsonb_array_length(parameter->'references'),0)<>0 THEN RAISE EXCEPTION 'Configured references cannot be omitted' USING ERRCODE='23514'; END IF;
  END LOOP;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_validation_evidence BEFORE INSERT OR UPDATE OR DELETE ON laboratory_result_validations FOR EACH ROW EXECUTE FUNCTION protect_validation_evidence();
CREATE TRIGGER protect_rectification_evidence BEFORE INSERT OR UPDATE OR DELETE ON laboratory_result_rectifications FOR EACH ROW EXECUTE FUNCTION protect_validation_evidence();
CREATE OR REPLACE FUNCTION protect_result_capture() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Capture history cannot be deleted' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM laboratory_order_exams l JOIN laboratory_orders o ON o.id=l.laboratory_order_id JOIN laboratory_exam_versions v ON v.id=l.clinical_version_id WHERE l.id=NEW.laboratory_order_exam_id AND l.processing_started_at IS NOT NULL AND o.status='in_process' AND v.status='published')
 THEN RAISE EXCEPTION 'Capture requires an explicitly started published line in an open processing order' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.revision<>1 OR NEW.status<>'preliminary' OR NEW.last_validation_id IS NOT NULL THEN RAISE EXCEPTION 'Capture must begin preliminary at revision one' USING ERRCODE='23514'; END IF;
 ELSE
  IF (NEW.id,NEW.laboratory_id,NEW.laboratory_order_id,NEW.laboratory_order_exam_id,NEW.laboratory_exam_id,NEW.clinical_version_id) IS DISTINCT FROM (OLD.id,OLD.laboratory_id,OLD.laboratory_order_id,OLD.laboratory_order_exam_id,OLD.laboratory_exam_id,OLD.clinical_version_id) OR NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'Immutable identity and unit revision advance required' USING ERRCODE='23514'; END IF;
  IF OLD.status='preliminary' AND NEW.status='validated' THEN
   IF NOT EXISTS(SELECT 1 FROM laboratory_result_validations v WHERE v.id=NEW.last_validation_id AND v.laboratory_order_exam_id=NEW.laboratory_order_exam_id AND v.approved_revision=OLD.revision AND v.transition_revision=NEW.revision AND v.results_snapshot=clinical_result_snapshot(NEW.laboratory_order_exam_id)) THEN RAISE EXCEPTION 'Validation evidence required' USING ERRCODE='23514'; END IF;
  ELSIF OLD.status='validated' AND NEW.status='preliminary' THEN
   IF NEW.last_validation_id IS DISTINCT FROM OLD.last_validation_id OR NOT EXISTS(SELECT 1 FROM laboratory_result_rectifications r WHERE r.validation_id=OLD.last_validation_id AND r.from_revision=OLD.revision AND r.to_revision=NEW.revision) THEN RAISE EXCEPTION 'Rectification evidence required' USING ERRCODE='23514'; END IF;
  ELSIF OLD.status='validated' OR NEW.last_validation_id IS DISTINCT FROM OLD.last_validation_id THEN RAISE EXCEPTION 'Validated results are not ordinarily editable' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION require_validation_transition() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c laboratory_order_result_captures;
BEGIN
 SELECT * INTO c FROM laboratory_order_result_captures WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id;
 IF TG_TABLE_NAME='laboratory_result_validations' THEN
  IF c.revision < NEW.transition_revision OR (c.revision=NEW.transition_revision AND (c.status<>'validated' OR c.last_validation_id<>NEW.id)) OR
    (c.revision>NEW.transition_revision AND NOT EXISTS(SELECT 1 FROM laboratory_result_rectifications r WHERE r.validation_id=NEW.id AND r.from_revision=NEW.transition_revision))
  THEN RAISE EXCEPTION 'Validation evidence requires its atomic capture transition' USING ERRCODE='23514'; END IF;
 ELSE
  IF c.revision < NEW.to_revision OR (c.revision=NEW.to_revision AND c.status<>'preliminary') THEN RAISE EXCEPTION 'Rectification requires its atomic preliminary transition' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER require_validation_transition AFTER INSERT ON laboratory_result_validations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_validation_transition();
CREATE CONSTRAINT TRIGGER require_rectification_transition AFTER INSERT ON laboratory_result_rectifications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_validation_transition();
CREATE OR REPLACE FUNCTION protect_result_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.event LIKE 'order_results.%' OR OLD.event LIKE 'result_validation.%' OR (TG_OP='UPDATE' AND (NEW.event LIKE 'order_results.%' OR NEW.event LIKE 'result_validation.%')) THEN RAISE EXCEPTION 'Clinical result audit history is immutable' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
SQL);
        // Preserve the BE-S2-06 parameter checks, strengthening its null guard for validated captures.
        $original = file_get_contents(__DIR__.'/2026_10_11_000001_create_clinical_order_results.php');
        preg_match('/CREATE OR REPLACE FUNCTION protect_result_parameter\(\).*?END \$\$;/s', $original, $matches);
        DB::unprepared(str_replace("IF TG_OP = 'DELETE' THEN\n  IF current_revision", "IF current_revision IS NULL THEN RAISE EXCEPTION 'Validated results require explicit rectification' USING ERRCODE = '23514'; END IF;\n IF TG_OP = 'DELETE' THEN\n  IF current_revision", $matches[0]));
    }

    public function down(): void
    {
        if (DB::table('laboratory_result_validations')->exists()) {
            throw new RuntimeException('Restore or export clinical validations before rolling back BE-S2-07.');
        }
        Schema::table('laboratory_order_result_captures', function (Blueprint $t) {
            $t->dropForeign('capture_validation_fk');
            $t->dropColumn('last_validation_id');
        });
        Schema::dropIfExists('laboratory_result_rectifications');
        Schema::dropIfExists('laboratory_result_validations');
        Schema::dropIfExists('validation_professionals');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE laboratories DROP CONSTRAINT laboratory_validation_policy_check');
            DB::statement('ALTER TABLE laboratory_order_result_captures DROP CONSTRAINT IF EXISTS result_capture_values_check');
            DB::statement("ALTER TABLE laboratory_order_result_captures ADD CONSTRAINT result_capture_values_check CHECK(revision>=1 AND status='preliminary')");
            $original = file_get_contents(__DIR__.'/2026_10_11_000001_create_clinical_order_results.php');
            foreach (['protect_result_capture', 'protect_result_parameter', 'protect_result_audit'] as $function) {
                preg_match('/CREATE OR REPLACE FUNCTION '.$function.'\(\).*?END \$\$;/s', $original, $match);
                DB::unprepared($match[0]);
            }
        }
        Schema::table('laboratories', fn (Blueprint $t) => $t->dropColumn('validation_policy'));
    }
};
