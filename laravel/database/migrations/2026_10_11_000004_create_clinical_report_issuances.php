<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', fn (Blueprint $t) => $t->jsonb('report_identity_snapshot')->nullable());
        Schema::table('laboratory_order_exams', fn (Blueprint $t) => $t->jsonb('report_area_snapshot')->nullable());
        Schema::create('laboratory_report_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('laboratory_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->jsonb('presentation');
        });
        Schema::create('laboratory_report_issuances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->string('number', 40)->unique();
            $t->unsignedBigInteger('sequence');
            $t->string('type', 10);
            $t->unsignedBigInteger('clinical_revision');
            $t->unsignedBigInteger('finalization_id')->nullable();
            $t->unsignedBigInteger('issued_by');
            $t->timestampTz('issued_at', 6);
            $t->string('idempotency_hash', 64);
            $t->string('request_hash', 64);
            $t->string('verification_hash', 64)->unique();
            $t->jsonb('identity_snapshot');
            $t->jsonb('clinical_snapshot');
            $t->jsonb('presentation_snapshot');
            $t->string('private_path', 150)->unique();
            $t->string('pdf_hash', 64);
            $t->unsignedBigInteger('pdf_size');
            $t->string('format_version', 30);
            $t->unique(['laboratory_id', 'idempotency_hash'], 'report_idempotency_unique');
            $t->unique(['laboratory_order_id', 'sequence'], 'report_sequence_unique');
            $t->unique(['laboratory_id', 'laboratory_order_id', 'id'], 'report_identity_unique');
            $t->foreign(['laboratory_id', 'laboratory_order_id'], 'report_order_fk')->references(['laboratory_id', 'id'])->on('laboratory_orders')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'issued_by'], 'report_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
            $t->foreign('finalization_id')->references('id')->on('clinical_order_finalizations')->restrictOnDelete();
        });
        Schema::create('laboratory_report_issuance_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('report_id');
            $t->unsignedBigInteger('laboratory_order_exam_id');
            $t->unsignedBigInteger('resolution_id');
            $t->jsonb('snapshot');
            $t->unique(['report_id', 'laboratory_order_exam_id']);
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id'], 'report_line_report_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_report_issuances')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'resolution_id'], 'report_line_resolution_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('clinical_line_resolutions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'], 'report_line_order_line_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_order_exams')->restrictOnDelete();
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_report_source_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='laboratory_orders' THEN
  IF NEW.report_identity_snapshot IS DISTINCT FROM OLD.report_identity_snapshot THEN RAISE EXCEPTION 'Order report identity snapshot is immutable' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.report_area_snapshot IS DISTINCT FROM OLD.report_area_snapshot THEN RAISE EXCEPTION 'Order line report area snapshot is immutable' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_report_identity BEFORE UPDATE ON laboratory_orders FOR EACH ROW EXECUTE FUNCTION protect_report_source_snapshot();
CREATE TRIGGER protect_report_area BEFORE UPDATE ON laboratory_order_exams FOR EACH ROW EXECUTE FUNCTION protect_report_source_snapshot();
CREATE OR REPLACE FUNCTION report_line_snapshot(target_line bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('line_id',l.id,'exam_code',l.exam_code,'exam_name',l.exam_name,'clinical_version_id',l.clinical_version_id,
 'clinical_configuration_source',coalesce(l.clinical_configuration_source,'historical'),'area',l.report_area_snapshot,
 'resolution',to_jsonb(r),'validation',to_jsonb(v),'exception',CASE WHEN d.id IS NULL THEN NULL ELSE jsonb_build_object('decision',to_jsonb(d),'request',to_jsonb(q)) END)
 FROM laboratory_order_exams l JOIN clinical_line_resolutions r ON r.laboratory_order_exam_id=l.id
 LEFT JOIN laboratory_result_validations v ON v.id=r.validation_id
 LEFT JOIN clinical_exception_decisions d ON d.id=r.exception_decision_id
 LEFT JOIN clinical_exception_requests q ON q.id=d.exception_request_id WHERE l.id=target_line;
$$;
ALTER TABLE laboratory_report_settings ADD CONSTRAINT report_settings_values CHECK(revision>0 AND jsonb_typeof(presentation)='object');
ALTER TABLE laboratory_report_issuances ADD CONSTRAINT report_values CHECK(sequence>0 AND clinical_revision>=0 AND type IN ('partial','complete') AND (type='complete')=(finalization_id IS NOT NULL) AND jsonb_typeof(clinical_snapshot)='array' AND jsonb_array_length(clinical_snapshot)>0 AND jsonb_typeof(identity_snapshot)='object' AND jsonb_typeof(presentation_snapshot)='object' AND pdf_size>0 AND pdf_hash ~ '^[a-f0-9]{64}$' AND verification_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND idempotency_hash ~ '^[a-f0-9]{64}$' AND private_path ~ '^reports/[0-9]+/[a-f0-9]{64}\.pdf$' AND format_version='donqer-clinical-1');
CREATE OR REPLACE FUNCTION protect_report_issuance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o laboratory_orders%ROWTYPE; f clinical_order_finalizations%ROWTYPE; x jsonb; r clinical_line_resolutions%ROWTYPE; c laboratory_order_result_captures%ROWTYPE; expected_ids jsonb;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Report evidence is immutable' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='laboratory_report_issuance_lines' THEN
  IF NOT EXISTS(SELECT 1 FROM laboratory_report_issuances p, jsonb_array_elements(p.clinical_snapshot) AS elements(value) WHERE p.id=NEW.report_id AND elements.value=NEW.snapshot) OR NEW.snapshot IS DISTINCT FROM report_line_snapshot(NEW.laboratory_order_exam_id) OR (NEW.snapshot->'resolution'->>'id')::bigint<>NEW.resolution_id THEN RAISE EXCEPTION 'Report line requires exact historical evidence' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO o FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;
 IF NEW.sequence<>(SELECT count(*)+1 FROM laboratory_report_issuances WHERE laboratory_order_id=o.id) THEN RAISE EXCEPTION 'Report sequence requires serialized order history' USING ERRCODE='23514'; END IF;
 IF o.report_identity_snapshot IS NOT NULL AND NEW.identity_snapshot - 'order_code' - 'ordered_at' IS DISTINCT FROM o.report_identity_snapshot THEN RAISE EXCEPTION 'Report must preserve order identity snapshot' USING ERRCODE='23514'; END IF;
 IF o.report_identity_snapshot IS NULL AND NEW.identity_snapshot->>'source' IS DISTINCT FROM 'confirmed_at_issuance' THEN RAISE EXCEPTION 'Historical order requires explicit current identity confirmation' USING ERRCODE='23514'; END IF;
 IF NEW.clinical_revision<>o.clinical_revision THEN RAISE EXCEPTION 'Report requires current order revision' USING ERRCODE='23514'; END IF;
 IF NEW.type='partial' THEN
  IF o.status NOT IN ('pending','in_process') OR EXISTS(SELECT 1 FROM clinical_order_finalizations WHERE laboratory_order_id=o.id) THEN RAISE EXCEPTION 'Partial report requires open order' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO f FROM clinical_order_finalizations WHERE id=NEW.finalization_id AND laboratory_id=NEW.laboratory_id AND laboratory_order_id=o.id;
  IF NOT FOUND OR o.status<>'completed' OR f.lines_snapshot IS DISTINCT FROM clinical_closure_snapshot(o.id) THEN RAISE EXCEPTION 'Complete report requires exact finalization evidence' USING ERRCODE='23514'; END IF;
  SELECT jsonb_agg((value->>'line_id')::bigint ORDER BY (value->>'line_id')::bigint) INTO expected_ids FROM jsonb_array_elements(f.lines_snapshot);
  IF expected_ids IS DISTINCT FROM (SELECT jsonb_agg((value->>'line_id')::bigint ORDER BY (value->>'line_id')::bigint) FROM jsonb_array_elements(NEW.clinical_snapshot)) THEN RAISE EXCEPTION 'Complete report must include every finalized line once' USING ERRCODE='23514'; END IF;
 END IF;
 IF jsonb_array_length(NEW.clinical_snapshot)<>(SELECT count(DISTINCT value->>'line_id') FROM jsonb_array_elements(NEW.clinical_snapshot)) THEN RAISE EXCEPTION 'Duplicate report lines' USING ERRCODE='23514'; END IF;
 FOR x IN SELECT value FROM jsonb_array_elements(NEW.clinical_snapshot) LOOP
  SELECT * INTO r FROM clinical_line_resolutions WHERE laboratory_order_exam_id=(x->>'line_id')::bigint AND laboratory_id=o.laboratory_id AND laboratory_order_id=o.id;
  IF NOT FOUND OR x IS DISTINCT FROM report_line_snapshot(r.laboratory_order_exam_id) THEN RAISE EXCEPTION 'Report snapshot must match immutable resolved line' USING ERRCODE='23514'; END IF;
  IF r.resolution_type='validated_result' THEN
   SELECT * INTO c FROM laboratory_order_result_captures WHERE laboratory_order_exam_id=r.laboratory_order_exam_id;
   IF c.status<>'validated' OR c.last_validation_id IS DISTINCT FROM r.validation_id OR c.revision IS DISTINCT FROM (SELECT transition_revision FROM laboratory_result_validations WHERE id=r.validation_id) THEN RAISE EXCEPTION 'Report validation must be current and exact' USING ERRCODE='23514'; END IF;
  END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_report_issuance BEFORE INSERT OR UPDATE OR DELETE ON laboratory_report_issuances FOR EACH ROW EXECUTE FUNCTION protect_report_issuance();
CREATE TRIGGER protect_report_issuance_line BEFORE INSERT OR UPDATE OR DELETE ON laboratory_report_issuance_lines FOR EACH ROW EXECUTE FUNCTION protect_report_issuance();
CREATE OR REPLACE FUNCTION require_report_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s jsonb;
BEGIN
 SELECT jsonb_agg(snapshot ORDER BY laboratory_order_exam_id) INTO s FROM laboratory_report_issuance_lines WHERE report_id=NEW.id;
 IF s IS DISTINCT FROM NEW.clinical_snapshot THEN RAISE EXCEPTION 'Report requires all snapshot lines atomically' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER require_report_lines AFTER INSERT ON laboratory_report_issuances DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_report_lines();
CREATE OR REPLACE FUNCTION protect_report_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.event LIKE 'order_report.%' THEN RAISE EXCEPTION 'Report audit is immutable' USING ERRCODE='23514'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER protect_report_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION protect_report_audit();
SQL);
    }

    public function down(): void
    {
        if (DB::table('laboratory_report_issuances')->exists()) {
            throw new LogicException('Report history requires an explicit backup/restoration strategy.');
        }
        Schema::dropIfExists('laboratory_report_issuance_lines');
        Schema::dropIfExists('laboratory_report_issuances');
        Schema::dropIfExists('laboratory_report_settings');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS protect_report_identity ON laboratory_orders; DROP TRIGGER IF EXISTS protect_report_area ON laboratory_order_exams; DROP TRIGGER IF EXISTS protect_report_audit ON audit_logs; DROP FUNCTION IF EXISTS report_line_snapshot(bigint);');
        }
        Schema::table('laboratory_order_exams', fn (Blueprint $t) => $t->dropColumn('report_area_snapshot'));
        Schema::table('laboratory_orders', fn (Blueprint $t) => $t->dropColumn('report_identity_snapshot'));
    }
};
