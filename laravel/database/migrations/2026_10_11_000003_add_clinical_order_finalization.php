<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', fn (Blueprint $t) => $t->unsignedBigInteger('clinical_revision')->default(0));
        Schema::create('clinical_exception_requests', function (Blueprint $t) {
            $t->id();
            foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'] as $c) {
                $t->unsignedBigInteger($c);
            }
            $t->unsignedBigInteger('revision')->default(1);
            $t->string('status', 20)->default('pending');
            $t->text('reason');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('requested_at', 6);
            $t->unsignedBigInteger('capture_revision');
            $t->timestampTz('processing_started_at', 6)->nullable();
            $t->string('clinical_configuration_source', 30);
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'], 'exception_line_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_order_exams')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'requested_by'], 'exception_request_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
            $t->unique(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'id'], 'exception_request_identity');
        });
        Schema::create('clinical_exception_decisions', function (Blueprint $t) {
            $t->id();
            foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id'] as $c) {
                $t->unsignedBigInteger($c);
            }
            $t->string('decision', 20);
            $t->text('reason')->nullable();
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('decided_at', 6);
            $t->jsonb('evidence_snapshot');
            $t->unique('exception_request_id');
            $t->unique(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'id'], 'exception_decision_identity');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id'], 'exception_decision_request_fk')->references(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'id'])->on('clinical_exception_requests')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'decided_by'], 'exception_decision_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('clinical_line_resolutions', function (Blueprint $t) {
            $t->id();
            foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'] as $c) {
                $t->unsignedBigInteger($c);
            }
            $t->string('resolution_type', 30);
            $t->unsignedBigInteger('validation_id')->nullable();
            $t->unsignedBigInteger('exception_decision_id')->nullable();
            $t->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('resolved_at', 6);
            $t->jsonb('evidence_snapshot');
            $t->unique('laboratory_order_exam_id');
            $t->unique(['laboratory_id', 'laboratory_order_id', 'id'], 'clinical_resolution_identity');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'], 'resolution_line_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_order_exams')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_exam_id', 'validation_id'], 'resolution_validation_fk')->references(['laboratory_id', 'laboratory_order_exam_id', 'id'])->on('laboratory_result_validations')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_decision_id'], 'resolution_exception_fk')->references(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'id'])->on('clinical_exception_decisions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'resolved_by'], 'resolution_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('clinical_order_finalizations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('clinical_revision');
            $t->foreignId('finalized_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('finalized_at', 6);
            $t->jsonb('lines_snapshot');
            $t->unique('laboratory_order_id');
            $t->foreign(['laboratory_id', 'laboratory_order_id'], 'clinical_finalization_order_fk')->references(['laboratory_id', 'id'])->on('laboratory_orders')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'finalized_by'], 'clinical_finalization_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE laboratory_orders ADD CONSTRAINT clinical_revision_check CHECK(clinical_revision>=0);
ALTER TABLE clinical_exception_requests ADD CONSTRAINT exception_request_state CHECK(length(btrim(reason))>0 AND ((status='pending' AND revision=1) OR (status IN ('approved','rejected') AND revision=2)));
CREATE UNIQUE INDEX exception_one_pending_per_line ON clinical_exception_requests(laboratory_order_exam_id) WHERE status='pending';
ALTER TABLE clinical_exception_decisions ADD CONSTRAINT exception_decision_values CHECK(decision IN ('approved','rejected') AND (decision<>'rejected' OR (reason IS NOT NULL AND length(btrim(reason))>0)) AND jsonb_typeof(evidence_snapshot)='object');
ALTER TABLE clinical_line_resolutions ADD CONSTRAINT clinical_resolution_values CHECK(jsonb_typeof(evidence_snapshot)='object' AND
 ((resolution_type='validated_result' AND validation_id IS NOT NULL AND exception_decision_id IS NULL) OR (resolution_type='authorized_exception' AND validation_id IS NULL AND exception_decision_id IS NOT NULL)));
ALTER TABLE clinical_order_finalizations ADD CONSTRAINT clinical_finalization_values CHECK(clinical_revision>=0 AND jsonb_typeof(lines_snapshot)='array' AND jsonb_array_length(lines_snapshot)>0);
CREATE OR REPLACE FUNCTION protect_clinical_exception_request() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'DELETE' THEN
  PERFORM 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;
  PERFORM 1 FROM laboratory_order_exams WHERE id=NEW.laboratory_order_exam_id FOR UPDATE;
 END IF;
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Clinical exception history cannot be deleted' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' THEN
  IF NEW IS DISTINCT FROM jsonb_populate_record(OLD,jsonb_build_object('status',NEW.status,'revision',NEW.revision)) OR OLD.status<>'pending' OR NEW.revision<>2 OR NOT EXISTS(SELECT 1 FROM clinical_exception_decisions d WHERE d.exception_request_id=OLD.id AND d.decision=NEW.status)
  THEN RAISE EXCEPTION 'Exception requests change only through their immutable decision' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.status<>'pending' OR NEW.revision<>1 OR EXISTS(SELECT 1 FROM clinical_line_resolutions WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id) OR EXISTS(SELECT 1 FROM laboratory_result_validations WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id)
  THEN RAISE EXCEPTION 'Exceptions cannot replace resolved or previously validated results' USING ERRCODE='23514'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id AND status IN ('pending','in_process')) THEN RAISE EXCEPTION 'Exception requires an open order' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_exception_request BEFORE INSERT OR UPDATE OR DELETE ON clinical_exception_requests FOR EACH ROW EXECUTE FUNCTION protect_clinical_exception_request();
CREATE OR REPLACE FUNCTION protect_clinical_exception_decision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE request clinical_exception_requests; line laboratory_order_exams; capture_rev bigint;
BEGIN
 IF TG_OP='INSERT' THEN
  PERFORM 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;
  PERFORM 1 FROM laboratory_order_exams WHERE id=NEW.laboratory_order_exam_id FOR UPDATE;
 END IF;
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Exception decisions are immutable' USING ERRCODE='23514'; END IF;
 SELECT * INTO request FROM clinical_exception_requests WHERE id=NEW.exception_request_id FOR UPDATE;
 IF request.status IS DISTINCT FROM 'pending' OR (NEW.decision='approved' AND request.requested_by=NEW.decided_by) THEN RAISE EXCEPTION 'Pending request and independent decision actor required' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id AND status IN ('pending','in_process')) THEN RAISE EXCEPTION 'Decision requires an open order' USING ERRCODE='23514'; END IF;
 IF NEW.decision='approved' THEN
  SELECT * INTO line FROM laboratory_order_exams WHERE id=NEW.laboratory_order_exam_id;
  SELECT coalesce((SELECT revision FROM laboratory_order_result_captures WHERE laboratory_order_exam_id=line.id),0) INTO capture_rev;
  IF request.capture_revision<>capture_rev OR request.processing_started_at IS DISTINCT FROM line.processing_started_at OR EXISTS(SELECT 1 FROM laboratory_result_validations WHERE laboratory_order_exam_id=line.id) OR EXISTS(SELECT 1 FROM clinical_line_resolutions WHERE laboratory_order_exam_id=line.id)
  THEN RAISE EXCEPTION 'Obsolete or validated lines cannot be approved by exception' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_exception_decision BEFORE INSERT OR UPDATE OR DELETE ON clinical_exception_decisions FOR EACH ROW EXECUTE FUNCTION protect_clinical_exception_decision();
CREATE OR REPLACE FUNCTION apply_clinical_exception_decision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE clinical_exception_requests SET status=NEW.decision,revision=2 WHERE id=NEW.exception_request_id;
 RETURN NEW;
END $$;
CREATE TRIGGER apply_clinical_exception_decision AFTER INSERT ON clinical_exception_decisions FOR EACH ROW EXECUTE FUNCTION apply_clinical_exception_decision();
CREATE OR REPLACE FUNCTION protect_clinical_line_resolution() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  PERFORM 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;
  PERFORM 1 FROM laboratory_order_exams WHERE id=NEW.laboratory_order_exam_id FOR UPDATE;
 END IF;
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Clinical resolutions are immutable' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id AND status IN ('pending','in_process')) THEN RAISE EXCEPTION 'Resolution requires an open order' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM clinical_exception_requests WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id AND status='pending') THEN RAISE EXCEPTION 'Pending exception blocks line resolution' USING ERRCODE='23514'; END IF;
 IF NEW.resolution_type='validated_result' THEN
  IF NOT EXISTS(SELECT 1 FROM laboratory_order_result_captures c JOIN laboratory_result_validations v ON v.id=c.last_validation_id JOIN laboratory_order_exams l ON l.id=c.laboratory_order_exam_id WHERE c.laboratory_order_exam_id=NEW.laboratory_order_exam_id AND EXISTS(SELECT 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id AND status='in_process') AND c.status='validated' AND c.last_validation_id=NEW.validation_id AND c.revision=v.transition_revision AND l.processing_started_at IS NOT NULL)
  THEN RAISE EXCEPTION 'Resolution must fix the current validated revision of a started line' USING ERRCODE='23514'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM clinical_exception_decisions d WHERE d.id=NEW.exception_decision_id AND d.decision='approved') OR EXISTS(SELECT 1 FROM laboratory_result_validations WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id) THEN RAISE EXCEPTION 'Approved exception without hidden validations required' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_line_resolution BEFORE INSERT OR UPDATE OR DELETE ON clinical_line_resolutions FOR EACH ROW EXECUTE FUNCTION protect_clinical_line_resolution();
CREATE OR REPLACE FUNCTION bump_order_clinical_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE laboratory_orders SET clinical_revision=clinical_revision+1 WHERE id=CASE WHEN TG_OP='DELETE' THEN OLD.laboratory_order_id ELSE NEW.laboratory_order_id END;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER bump_composition_clinical_revision AFTER INSERT OR DELETE ON laboratory_order_exams FOR EACH ROW EXECUTE FUNCTION bump_order_clinical_revision();
CREATE TRIGGER bump_exception_clinical_revision AFTER INSERT OR UPDATE ON clinical_exception_requests FOR EACH ROW EXECUTE FUNCTION bump_order_clinical_revision();
CREATE TRIGGER bump_resolution_clinical_revision AFTER INSERT ON clinical_line_resolutions FOR EACH ROW EXECUTE FUNCTION bump_order_clinical_revision();
CREATE OR REPLACE FUNCTION clinical_closure_snapshot(target_order bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT coalesce(jsonb_agg(jsonb_build_object('line_id',l.id,'laboratory_exam_id',l.laboratory_exam_id,'exam_code',l.exam_code,'exam_name',l.exam_name,
 'clinical_version_id',l.clinical_version_id,'clinical_configuration_source',coalesce(l.clinical_configuration_source,'historical'),
 'resolution_id',r.id,'resolution_type',r.resolution_type,'validation_id',r.validation_id,'exception_decision_id',r.exception_decision_id) ORDER BY l.id),'[]'::jsonb)
 FROM laboratory_order_exams l JOIN clinical_line_resolutions r ON r.laboratory_order_exam_id=l.id WHERE l.laboratory_order_id=target_order;
$$;
CREATE OR REPLACE FUNCTION protect_clinical_order_finalization() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE order_row laboratory_orders; expected jsonb; count_lines bigint;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Clinical finalization evidence is immutable' USING ERRCODE='23514'; END IF;
 SELECT * INTO order_row FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;
 SELECT count(*) INTO count_lines FROM laboratory_order_exams WHERE laboratory_order_id=order_row.id;
 expected:=clinical_closure_snapshot(order_row.id);
 IF order_row.status NOT IN ('pending','in_process') OR count_lines=0 OR jsonb_array_length(expected)<>count_lines OR NEW.lines_snapshot IS DISTINCT FROM expected OR NEW.clinical_revision IS DISTINCT FROM order_row.clinical_revision OR EXISTS(SELECT 1 FROM clinical_exception_requests WHERE laboratory_order_id=order_row.id AND status='pending')
 THEN RAISE EXCEPTION 'Exact resolved composition and current open clinical revision required' USING ERRCODE='23514'; END IF;
 IF order_row.status='pending' AND EXISTS(SELECT 1 FROM clinical_line_resolutions WHERE laboratory_order_id=order_row.id AND resolution_type<>'authorized_exception') THEN RAISE EXCEPTION 'Pending closure requires approved exceptions for every line' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_order_finalization BEFORE INSERT OR UPDATE OR DELETE ON clinical_order_finalizations FOR EACH ROW EXECUTE FUNCTION protect_clinical_order_finalization();
CREATE OR REPLACE FUNCTION protect_clinically_closed_order() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN
  IF EXISTS(SELECT 1 FROM clinical_order_finalizations WHERE laboratory_order_id=OLD.id) THEN RAISE EXCEPTION 'Closed clinical history cannot be deleted' USING ERRCODE='23514'; END IF;
  RETURN OLD;
 END IF;
 IF NEW.clinical_revision IS DISTINCT FROM OLD.clinical_revision AND NEW.clinical_revision<>OLD.clinical_revision+1 THEN RAISE EXCEPTION 'Clinical revisions advance exactly once and never decrease' USING ERRCODE='23514'; END IF;
 IF OLD.status='completed' AND EXISTS(SELECT 1 FROM clinical_order_finalizations WHERE laboratory_order_id=OLD.id) AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'Finalized order snapshots and state are immutable' USING ERRCODE='23514'; END IF;
 IF NEW.status='completed' AND OLD.status<>'completed' THEN
  IF NEW.clinical_revision<>OLD.clinical_revision+1 OR NOT EXISTS(SELECT 1 FROM clinical_order_finalizations f WHERE f.laboratory_order_id=OLD.id AND f.clinical_revision=OLD.clinical_revision AND f.lines_snapshot=clinical_closure_snapshot(OLD.id)) THEN RAISE EXCEPTION 'Completion requires atomic explicit clinical evidence' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinically_closed_order BEFORE UPDATE OR DELETE ON laboratory_orders FOR EACH ROW EXECUTE FUNCTION protect_clinically_closed_order();
CREATE OR REPLACE FUNCTION require_clinical_order_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id AND status='completed' AND clinical_revision=NEW.clinical_revision+1) THEN RAISE EXCEPTION 'Finalization evidence and completed transition must commit together' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER require_clinical_order_transition AFTER INSERT ON clinical_order_finalizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_clinical_order_transition();
CREATE OR REPLACE FUNCTION protect_resolved_order_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_order bigint; target_line bigint;
BEGIN
 target_order:=CASE WHEN TG_OP='DELETE' THEN OLD.laboratory_order_id ELSE NEW.laboratory_order_id END;
 target_line:=CASE WHEN TG_OP='DELETE' THEN OLD.id ELSE NEW.id END;
 IF EXISTS(SELECT 1 FROM clinical_order_finalizations WHERE laboratory_order_id=target_order) OR (TG_OP<>'INSERT' AND EXISTS(SELECT 1 FROM clinical_line_resolutions WHERE laboratory_order_exam_id=target_line)) THEN RAISE EXCEPTION 'Finalized composition and resolved lines are immutable' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER protect_resolved_order_line BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_exams FOR EACH ROW EXECUTE FUNCTION protect_resolved_order_line();
CREATE OR REPLACE FUNCTION protect_clinical_resolution_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.event LIKE 'order_clinical.%' OR (TG_OP='UPDATE' AND NEW.event LIKE 'order_clinical.%') THEN RAISE EXCEPTION 'Clinical resolution audit is immutable' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_resolution_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION protect_clinical_resolution_audit();
CREATE OR REPLACE FUNCTION protect_resolved_clinical_activity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE line_id bigint;
BEGIN
 line_id:=CASE WHEN TG_OP='DELETE' THEN OLD.laboratory_order_exam_id ELSE NEW.laboratory_order_exam_id END;
 IF EXISTS(SELECT 1 FROM clinical_line_resolutions WHERE laboratory_order_exam_id=line_id) THEN RAISE EXCEPTION 'Resolved lines require a future controlled correction procedure' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='laboratory_order_result_captures' THEN
  IF TG_OP='UPDATE' AND NEW.status='validated' AND EXISTS(SELECT 1 FROM clinical_exception_requests WHERE laboratory_order_exam_id=line_id AND status='pending') THEN RAISE EXCEPTION 'Pending exception blocks validation' USING ERRCODE='23514'; END IF;
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER protect_resolved_capture BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_result_captures FOR EACH ROW EXECUTE FUNCTION protect_resolved_clinical_activity();
CREATE TRIGGER protect_resolved_result BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_results FOR EACH ROW EXECUTE FUNCTION protect_resolved_clinical_activity();
SQL);
    }

    public function down(): void
    {
        if (DB::table('clinical_line_resolutions')->exists() || DB::table('clinical_exception_requests')->exists() || DB::table('clinical_order_finalizations')->exists()) {
            throw new RuntimeException('Clinical resolution history requires explicit backup/restoration before rollback.');
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['laboratory_order_result_captures' => 'protect_resolved_capture', 'laboratory_order_results' => 'protect_resolved_result', 'laboratory_order_exams' => 'bump_composition_clinical_revision', 'laboratory_orders' => 'protect_clinically_closed_order', 'audit_logs' => 'protect_clinical_resolution_audit'] as $table => $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS $trigger ON $table");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS protect_resolved_order_line ON laboratory_order_exams');
        }
        foreach (['clinical_order_finalizations', 'clinical_line_resolutions', 'clinical_exception_decisions', 'clinical_exception_requests'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('laboratory_orders', fn (Blueprint $t) => $t->dropColumn('clinical_revision'));
    }
};
