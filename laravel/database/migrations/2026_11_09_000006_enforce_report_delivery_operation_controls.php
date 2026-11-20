<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Existing history is preserved. Conflicting authorizations require explicit reconciliation.
CREATE UNIQUE INDEX delivery_exception_one_preparation ON report_delivery_preparations(exception_authorization_id) WHERE exception_authorization_id IS NOT NULL;
ALTER TABLE report_delivery_preparations ADD CONSTRAINT delivery_preparation_fifteen_minutes CHECK(expires_at<=prepared_at+interval '15 minutes') NOT VALID;

CREATE OR REPLACE FUNCTION report_delivery_clinical_block(lab bigint, ord bigint, report bigint) RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE o laboratory_orders%ROWTYPE; r laboratory_report_issuances%ROWTYPE; x jsonb; actual jsonb;
BEGIN
 SELECT * INTO o FROM laboratory_orders WHERE laboratory_id=lab AND id=ord;
 SELECT * INTO r FROM laboratory_report_issuances WHERE laboratory_id=lab AND laboratory_order_id=ord AND id=report;
 IF o.id IS NULL OR r.id IS NULL THEN RETURN 'report_clinical_evidence_unavailable'; END IF;
 IF o.clinical_revision IS DISTINCT FROM r.clinical_revision OR o.status NOT IN ('pending','in_process','completed') THEN RETURN 'report_clinical_evidence_changed'; END IF;
 IF r.type='complete' AND (o.status<>'completed' OR NOT EXISTS(SELECT 1 FROM clinical_order_finalizations f WHERE f.id=r.finalization_id AND f.laboratory_id=lab AND f.laboratory_order_id=ord AND f.lines_snapshot=clinical_closure_snapshot(ord))) THEN RETURN 'report_release_required'; END IF;
 IF jsonb_array_length(r.clinical_snapshot)=0 THEN RETURN 'report_release_required'; END IF;
 FOR x IN SELECT value FROM jsonb_array_elements(r.clinical_snapshot) LOOP
  IF NOT EXISTS(SELECT 1 FROM laboratory_report_issuance_lines i JOIN clinical_line_resolutions resolution ON resolution.id=i.resolution_id AND resolution.laboratory_order_exam_id=i.laboratory_order_exam_id
    WHERE i.laboratory_id=lab AND i.laboratory_order_id=ord AND i.report_id=report AND i.laboratory_order_exam_id=(x->>'line_id')::bigint AND i.snapshot=x
    AND (resolution.resolution_type<>'validated_result' OR EXISTS(SELECT 1 FROM laboratory_order_result_captures c JOIN laboratory_result_validations v ON v.id=c.last_validation_id
     WHERE c.laboratory_id=lab AND c.laboratory_order_id=ord AND c.laboratory_order_exam_id=i.laboratory_order_exam_id AND c.status='validated' AND c.last_validation_id=resolution.validation_id AND v.transition_revision=c.revision))) THEN RETURN 'report_release_required'; END IF;
  actual:=CASE WHEN r.format_version='donqer-clinical-1' THEN report_line_snapshot_v1((x->>'line_id')::bigint) ELSE report_line_snapshot((x->>'line_id')::bigint) END;
  IF actual IS DISTINCT FROM x THEN RETURN 'report_clinical_evidence_changed'; END IF;
 END LOOP;
 RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION report_delivery_cash_evidence(lab bigint, ord bigint) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE o laboratory_orders%ROWTYPE; s laboratory_order_financial_states%ROWTYPE; paid numeric; returned numeric; credit numeric; result_status text:='unavailable'; evidence text:='none'; version text;
BEGIN
 SELECT * INTO o FROM laboratory_orders WHERE laboratory_id=lab AND id=ord;
 SELECT * INTO s FROM laboratory_order_financial_states WHERE laboratory_id=lab AND laboratory_order_id=ord;
 IF o.delivery_policy_snapshot IS NOT NULL AND s.id IS NOT NULL AND EXISTS(SELECT 1 FROM laboratory_order_financial_revisions f WHERE f.laboratory_id=lab AND f.laboratory_order_id=ord AND f.revision=s.revision AND f.total_snapshot=o.total) THEN
  SELECT coalesce(sum(m.amount) FILTER(WHERE m.type='payment' AND NOT EXISTS(SELECT 1 FROM laboratory_order_cash_movements v WHERE v.laboratory_id=lab AND v.laboratory_order_id=ord AND v.type='payment_void' AND v.target_payment_id=m.id)),0), coalesce(sum(m.amount) FILTER(WHERE m.type='refund'),0) INTO paid,returned FROM laboratory_order_cash_movements m WHERE m.laboratory_id=lab AND m.laboratory_order_id=ord;
  IF paid>=returned AND paid<=9999999999999999.99 AND returned<=9999999999999999.99 THEN
   result_status:='not_satisfied'; version:='policy:'||(o.delivery_policy_snapshot->>'source_revision')||';financial:'||s.revision;
   IF paid-returned>=o.total THEN result_status:='satisfied'; evidence:='full_payment';
   ELSIF o.delivery_policy_snapshot->>'mode'='payment_or_credit' THEN
    SELECT amount INTO credit FROM laboratory_order_credit_events WHERE id=s.active_credit_authorization_id AND laboratory_id=lab AND laboratory_order_id=ord AND type='authorized';
    IF credit>=o.total-paid+returned THEN result_status:='satisfied'; evidence:='authorized_credit'; END IF;
   END IF;
  END IF;
 END IF;
 RETURN jsonb_build_object('status',result_status,'evidence_type',evidence,'provider','order_cash_v1','evidence_reference',ord::text,'evidence_revision',version);
END $$;

CREATE OR REPLACE FUNCTION enforce_report_delivery_operation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE blocker text; p report_delivery_preparations%ROWTYPE; a report_delivery_exception_authorizations%ROWTYPE; evidence jsonb; expected_fingerprint text; operation_at timestamptz;
BEGIN
 -- Same order mutex as issuance and every financial writer, before reading evidence.
 PERFORM 1 FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR UPDATE;
 blocker:=report_delivery_clinical_block(NEW.laboratory_id,NEW.laboratory_order_id,NEW.report_id);
 IF blocker IS NOT NULL THEN RAISE EXCEPTION 'Report operation blocked: %',blocker USING ERRCODE='23514'; END IF;
 evidence:=report_delivery_cash_evidence(NEW.laboratory_id,NEW.laboratory_order_id);
 IF NEW.evaluation_snapshot-'fingerprint'-'checked_at' IS DISTINCT FROM evidence THEN RAISE EXCEPTION 'Report operation requires current financial evidence' USING ERRCODE='23514'; END IF;
 expected_fingerprint:=encode(sha256(convert_to(replace(replace(json_build_object('status',evidence->>'status','evidence_type',evidence->>'evidence_type','provider',evidence->>'provider','evidence_reference',evidence->>'evidence_reference','evidence_revision',evidence->>'evidence_revision')::text, ' : ', ':'), ', ', ','),'UTF8')),'hex');
 IF NEW.evaluation_snapshot->>'fingerprint' IS DISTINCT FROM expected_fingerprint THEN RAISE EXCEPTION 'Report operation requires a verifiable financial fingerprint' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='report_delivery_preparations' THEN
  p:=NEW; operation_at:=coalesce(NEW.downloaded_at,NEW.prepared_at);
 ELSE
  SELECT * INTO p FROM report_delivery_preparations WHERE id=NEW.preparation_id FOR UPDATE;
  operation_at:=NEW.delivered_at;
  IF NEW.delivered_by IS DISTINCT FROM p.prepared_by THEN RAISE EXCEPTION 'Delivery declaration belongs to another employee' USING ERRCODE='23514'; END IF;
 END IF;
 IF p.recipient_snapshot->'authorization_confirmed' IS DISTINCT FROM 'true'::jsonb OR p.recipient_snapshot->>'verified_by' IS DISTINCT FROM p.prepared_by::text OR nullif(btrim(p.recipient_snapshot->>'declaration'),'') IS NULL OR nullif(p.recipient_snapshot->>'verified_at','') IS NULL THEN RAISE EXCEPTION 'Explicit operation-bound declaration required' USING ERRCODE='23514'; END IF;
 IF p.downloaded_by IS NOT NULL AND p.downloaded_by IS DISTINCT FROM p.prepared_by THEN RAISE EXCEPTION 'Download declaration belongs to another employee' USING ERRCODE='23514'; END IF;
 IF operation_at>=p.expires_at THEN RAISE EXCEPTION 'Delivery preparation expired' USING ERRCODE='23514'; END IF;
 IF p.exception_authorization_id IS NOT NULL THEN
  SELECT * INTO a FROM report_delivery_exception_authorizations WHERE id=p.exception_authorization_id;
  IF a.id IS NULL OR operation_at<a.authorized_at OR operation_at>=a.authorized_at+interval '15 minutes' OR p.expires_at>a.authorized_at+interval '15 minutes' OR NOT EXISTS(SELECT 1 FROM report_delivery_exceptions x WHERE x.id=a.exception_id AND x.status='authorized') THEN RAISE EXCEPTION 'Exact commercial authorization expired or unavailable' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER zz_enforce_delivery_preparation BEFORE INSERT OR UPDATE ON report_delivery_preparations FOR EACH ROW EXECUTE FUNCTION enforce_report_delivery_operation();
CREATE TRIGGER zz_enforce_delivery_operation BEFORE INSERT ON report_deliveries FOR EACH ROW EXECUTE FUNCTION enforce_report_delivery_operation();
CREATE TRIGGER protect_delivery_block_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW WHEN (OLD.event='order_report.delivery_blocked') EXECUTE FUNCTION protect_report_delivery_history();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Delivery control history requires an explicit backup/restoration strategy.');
    }
};
