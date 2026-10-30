<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_exception_evidence_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('laboratory_id')->constrained('laboratories');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('laboratory_order_exam_id');
            $t->foreignId('exception_request_id')->constrained('clinical_exception_requests');
            $t->foreignId('reviewed_by')->constrained('users');
            $t->string('kind', 20);
            $t->text('justification');
            $t->jsonb('dependency_snapshot');
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['exception_request_id', 'id']);
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id'], 'exception_evidence_request_fk')->references(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'id'])->on('clinical_exception_requests')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'reviewed_by'], 'exception_evidence_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_exception_dependencies(line_id bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('clinical', clinical_replacement_dependencies(line_id), 'assessments',
 coalesce((SELECT jsonb_agg(to_jsonb(a) ORDER BY a.id) FROM clinical_viability_assessments a WHERE a.laboratory_order_exam_id=line_id
 AND NOT EXISTS(SELECT 1 FROM clinical_viability_assessments n WHERE n.laboratory_order_exam_id=a.laboratory_order_exam_id AND n.subject=a.subject AND n.intention_id IS NOT DISTINCT FROM a.intention_id AND n.revision>a.revision)), '[]'))
$$;
CREATE OR REPLACE FUNCTION guard_clinical_exception_evidence_review() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE request clinical_exception_requests; prior clinical_exception_evidence_reviews;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Clinical evidence reviews are immutable' USING ERRCODE='23514'; END IF;
 SELECT * INTO request FROM clinical_exception_requests WHERE id=NEW.exception_request_id;
 PERFORM 1 FROM laboratory_orders WHERE id=request.laboratory_order_id FOR UPDATE;
 PERFORM 1 FROM laboratory_order_exams WHERE id=request.laboratory_order_exam_id FOR UPDATE;
 IF request.id IS NULL OR request.status<>'pending' OR NEW.laboratory_id<>request.laboratory_id OR NEW.laboratory_order_id<>request.laboratory_order_id OR NEW.laboratory_order_exam_id<>request.laboratory_order_exam_id
 OR NOT EXISTS(SELECT 1 FROM laboratory_user WHERE laboratory_id=NEW.laboratory_id AND user_id=NEW.reviewed_by)
 OR NEW.kind NOT IN ('request','review') OR (NEW.kind='request' AND NEW.reviewed_by<>request.requested_by) OR length(btrim(NEW.justification))=0
 OR NEW.dependency_snapshot IS DISTINCT FROM clinical_exception_dependencies(request.laboratory_order_exam_id)
 THEN RAISE EXCEPTION 'Invalid clinical evidence review context' USING ERRCODE='23514'; END IF;
 SELECT * INTO prior FROM clinical_exception_evidence_reviews WHERE exception_request_id=request.id ORDER BY id DESC LIMIT 1;
 IF NEW.kind='request' AND prior.id IS NOT NULL THEN RAISE EXCEPTION 'Preserve the initial evidence and explicit successor reviews' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER guard_clinical_exception_evidence_review BEFORE INSERT OR UPDATE OR DELETE ON clinical_exception_evidence_reviews FOR EACH ROW EXECUTE FUNCTION guard_clinical_exception_evidence_review();
CREATE TRIGGER bump_exception_evidence_review AFTER INSERT ON clinical_exception_evidence_reviews FOR EACH ROW WHEN (NEW.kind='review') EXECUTE FUNCTION bump_order_clinical_revision();
CREATE OR REPLACE FUNCTION require_current_clinical_exception_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE line_id bigint;
BEGIN
 IF NEW.decision<>'approved' THEN RETURN NEW; END IF;
 SELECT laboratory_order_exam_id INTO line_id FROM clinical_exception_requests WHERE id=NEW.exception_request_id;
 PERFORM 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;
 PERFORM 1 FROM laboratory_order_exams WHERE id=line_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM clinical_exception_evidence_reviews r WHERE r.id=(SELECT max(id) FROM clinical_exception_evidence_reviews WHERE exception_request_id=NEW.exception_request_id)
 AND r.dependency_snapshot=clinical_exception_dependencies(line_id))
 THEN RAISE EXCEPTION 'Approval requires a current explicit clinical evidence review' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM clinical_replacement_intents i WHERE i.laboratory_order_exam_id=line_id
 AND NOT EXISTS(SELECT 1 FROM clinical_replacement_events e JOIN clinical_replacement_links l ON l.id=e.link_id
 JOIN laboratory_sample_associations a ON a.id=l.association_id JOIN laboratory_samples s ON s.id=a.laboratory_sample_id
 JOIN laboratory_order_sample_requirements r ON r.id=a.sample_requirement_id JOIN laboratory_exam_versions v ON v.id=r.clinical_version_id
 WHERE e.id=(SELECT max(id) FROM clinical_replacement_events WHERE intention_id=i.id) AND e.event='fulfilled' AND a.released_at IS NULL AND s.status='accepted'
 AND sample_compatibility_errors(r.configuration,to_jsonb(s),(v.configuration->>'schema_version')::integer,sample_operation_time())='[]'::jsonb)
 AND NOT EXISTS(SELECT 1 FROM clinical_viability_assessments a WHERE a.id=(SELECT id FROM clinical_viability_assessments WHERE intention_id=i.id AND subject='replacement' ORDER BY revision DESC LIMIT 1)
 AND a.outcome='non_viable' AND a.dependency_snapshot=clinical_replacement_dependencies(line_id)))
 THEN RAISE EXCEPTION 'Pending replacement requires a current non viable clinical assessment' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER require_current_clinical_exception_evidence BEFORE INSERT ON clinical_exception_decisions FOR EACH ROW EXECUTE FUNCTION require_current_clinical_exception_evidence();
SQL);
    }

    public function down(): void
    {
        if (DB::table('clinical_exception_evidence_reviews')->exists()) {
            throw new RuntimeException('Clinical evidence history requires explicit restoration.');
        }
        DB::unprepared('DROP TRIGGER require_current_clinical_exception_evidence ON clinical_exception_decisions; DROP FUNCTION require_current_clinical_exception_evidence(); DROP TRIGGER bump_exception_evidence_review ON clinical_exception_evidence_reviews; DROP TRIGGER guard_clinical_exception_evidence_review ON clinical_exception_evidence_reviews; DROP FUNCTION guard_clinical_exception_evidence_review();');
        Schema::dropIfExists('clinical_exception_evidence_reviews');
        DB::statement('DROP FUNCTION clinical_exception_dependencies(bigint)');
    }
};
