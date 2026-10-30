<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->guards(true);
    }

    public function down(): void
    {
        $this->guards(false);
    }

    private function guards(bool $hardened): void
    {
        // Keep the existing audit trigger, including its original protected namespace.
        $additional = $hardened ? " OR starts_with(OLD.event, 'clinical_replacement.') OR starts_with(OLD.event, 'clinical_reason.') OR (TG_OP='UPDATE' AND (starts_with(NEW.event, 'clinical_replacement.') OR starts_with(NEW.event, 'clinical_reason.')))" : '';
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION protect_clinical_resolution_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.event LIKE 'order_clinical.%' OR (TG_OP='UPDATE' AND NEW.event LIKE 'order_clinical.%'){$additional}
 THEN RAISE EXCEPTION 'Clinical resolution audit is immutable' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
SQL);
        // Only immutable context is read before locking; current state is read after the wait.
        $locks = $hardened ? <<<'SQL'
 SELECT laboratory_order_id, laboratory_order_exam_id INTO target_order, target_line FROM clinical_exception_requests WHERE id=NEW.exception_request_id;
 PERFORM 1 FROM laboratory_orders WHERE id=target_order FOR UPDATE;
 PERFORM 1 FROM laboratory_order_exams WHERE id=target_line FOR UPDATE;
 SELECT * INTO request FROM clinical_exception_requests WHERE id=NEW.exception_request_id FOR UPDATE;
SQL
            : <<<'SQL'
 SELECT * INTO request FROM clinical_exception_requests WHERE id=NEW.exception_request_id;
 PERFORM 1 FROM laboratory_orders WHERE id=request.laboratory_order_id FOR UPDATE;
 PERFORM 1 FROM laboratory_order_exams WHERE id=request.laboratory_order_exam_id FOR UPDATE;
SQL;
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION guard_clinical_exception_evidence_review() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE request clinical_exception_requests; prior clinical_exception_evidence_reviews; target_order bigint; target_line bigint;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Clinical evidence reviews are immutable' USING ERRCODE='23514'; END IF;
{$locks}
 IF request.id IS NULL OR request.status<>'pending' OR NEW.laboratory_id<>request.laboratory_id OR NEW.laboratory_order_id<>request.laboratory_order_id OR NEW.laboratory_order_exam_id<>request.laboratory_order_exam_id
 OR NOT EXISTS(SELECT 1 FROM laboratory_user WHERE laboratory_id=NEW.laboratory_id AND user_id=NEW.reviewed_by)
 OR NEW.kind NOT IN ('request','review') OR (NEW.kind='request' AND NEW.reviewed_by<>request.requested_by) OR length(btrim(NEW.justification))=0
 OR NEW.dependency_snapshot IS DISTINCT FROM clinical_exception_dependencies(request.laboratory_order_exam_id)
 THEN RAISE EXCEPTION 'Invalid clinical evidence review context' USING ERRCODE='23514'; END IF;
 SELECT * INTO prior FROM clinical_exception_evidence_reviews WHERE exception_request_id=request.id ORDER BY id DESC LIMIT 1;
 IF NEW.kind='request' AND prior.id IS NOT NULL THEN RAISE EXCEPTION 'Preserve the initial evidence and explicit successor reviews' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);
    }
};
