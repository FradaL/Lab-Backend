<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE report_validity_events (
 id bigserial PRIMARY KEY,
 laboratory_id bigint NOT NULL,
 laboratory_order_id bigint NOT NULL,
 report_id bigint NOT NULL,
 revision integer NOT NULL CHECK (revision > 0),
 state text NOT NULL CHECK (state IN ('released','suspended','invalidated','superseded')),
 replacement_report_id bigint,
 reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 1 AND 1000),
 actor_id bigint NOT NULL,
 occurred_at timestamptz(6) NOT NULL,
 idempotency_hash text NOT NULL CHECK (idempotency_hash ~ '^[a-f0-9]{64}$'),
 request_hash text NOT NULL CHECK (request_hash ~ '^[a-f0-9]{64}$'),
 UNIQUE (laboratory_id, idempotency_hash),
 UNIQUE (report_id, revision),
 FOREIGN KEY (laboratory_id,laboratory_order_id,report_id) REFERENCES laboratory_report_issuances(laboratory_id,laboratory_order_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (laboratory_id,laboratory_order_id,replacement_report_id) REFERENCES laboratory_report_issuances(laboratory_id,laboratory_order_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (laboratory_id,actor_id) REFERENCES laboratory_user(laboratory_id,user_id) ON DELETE RESTRICT,
 CHECK ((state='superseded')=(replacement_report_id IS NOT NULL)),
 CHECK (replacement_report_id IS NULL OR replacement_report_id<>report_id)
);
ALTER TABLE report_delivery_preparations ADD COLUMN validity_revision integer;

-- Keep the exact existing clinical checks as the evidence layer. CREATE OR REPLACE
-- is required because migrate:fresh leaves standalone functions in PostgreSQL.
CREATE OR REPLACE FUNCTION report_delivery_evidence_block(lab bigint, ord bigint, report bigint) RETURNS text LANGUAGE plpgsql STABLE AS $$
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
CREATE OR REPLACE FUNCTION report_delivery_clinical_block(lab bigint, ord bigint, report bigint) RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE current_state text;
BEGIN
 SELECT state INTO current_state FROM report_validity_events WHERE laboratory_id=lab AND laboratory_order_id=ord AND report_id=report ORDER BY revision DESC LIMIT 1;
 IF current_state IS NULL THEN RETURN 'report_release_required'; END IF;
 IF current_state<>'released' THEN RETURN 'report_'||current_state; END IF;
 RETURN report_delivery_evidence_block(lab,ord,report);
END $$;

CREATE OR REPLACE FUNCTION enforce_report_validity_event() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous report_validity_events%ROWTYPE; replacement laboratory_report_issuances%ROWTYPE; source laboratory_report_issuances%ROWTYPE;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Report validity history is immutable' USING ERRCODE='23514'; END IF;
 PERFORM 1 FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR UPDATE;
 SELECT * INTO source FROM laboratory_report_issuances WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id AND id=NEW.report_id;
 SELECT * INTO previous FROM report_validity_events WHERE report_id=NEW.report_id ORDER BY revision DESC LIMIT 1;
 IF NEW.revision<>coalesce(previous.revision,0)+1 OR previous.state IN ('invalidated','superseded') THEN RAISE EXCEPTION 'Invalid report validity transition' USING ERRCODE='23514'; END IF;
 IF NEW.state='released' THEN
  IF previous.state='released' OR report_delivery_evidence_block(NEW.laboratory_id,NEW.laboratory_order_id,NEW.report_id) IS NOT NULL THEN RAISE EXCEPTION 'Release requires current clinical evidence' USING ERRCODE='23514'; END IF;
 END IF;
 IF NEW.state='superseded' THEN
  SELECT * INTO replacement FROM laboratory_report_issuances WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id AND id=NEW.replacement_report_id;
  IF replacement.id IS NULL OR replacement.sequence<=source.sequence OR report_delivery_clinical_block(NEW.laboratory_id,NEW.laboratory_order_id,NEW.replacement_report_id) IS NOT NULL
   OR EXISTS(SELECT 1 FROM laboratory_report_issuance_lines old_line WHERE old_line.report_id=source.id AND NOT EXISTS(SELECT 1 FROM laboratory_report_issuance_lines new_line WHERE new_line.report_id=replacement.id AND new_line.laboratory_order_exam_id=old_line.laboratory_order_exam_id)) THEN
   RAISE EXCEPTION 'Replacement requires a newer released current report covering every replaced line' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_report_validity BEFORE INSERT OR UPDATE OR DELETE ON report_validity_events FOR EACH ROW EXECUTE FUNCTION enforce_report_validity_event();

CREATE OR REPLACE FUNCTION enforce_report_delivery_validity_revision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE preparation report_delivery_preparations%ROWTYPE; current_revision integer; current_state text;
BEGIN
 IF TG_TABLE_NAME='report_delivery_preparations' THEN
  preparation:=NEW;
 ELSE
  SELECT * INTO preparation FROM report_delivery_preparations WHERE id=NEW.preparation_id;
 END IF;
 SELECT revision,state INTO current_revision,current_state FROM report_validity_events WHERE report_id=preparation.report_id ORDER BY revision DESC LIMIT 1;
 IF current_state IS DISTINCT FROM 'released' OR preparation.validity_revision IS DISTINCT FROM current_revision THEN
  RAISE EXCEPTION 'Report validity changed after preparation' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER zz_enforce_delivery_preparation_validity BEFORE INSERT OR UPDATE ON report_delivery_preparations FOR EACH ROW EXECUTE FUNCTION enforce_report_delivery_validity_revision();
CREATE TRIGGER zz_enforce_delivery_confirmation_validity BEFORE INSERT ON report_deliveries FOR EACH ROW EXECUTE FUNCTION enforce_report_delivery_validity_revision();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Report validity history requires an explicit backup/restoration strategy.');
    }
};
