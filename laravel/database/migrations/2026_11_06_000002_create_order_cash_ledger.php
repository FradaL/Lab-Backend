<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE laboratory_order_financial_states (
 id bigserial PRIMARY KEY, laboratory_id bigint NOT NULL, laboratory_order_id bigint NOT NULL,
 revision integer NOT NULL CHECK (revision > 0), active_credit_authorization_id bigint CHECK (active_credit_authorization_id IS NULL),
 updated_at timestamptz(6) NOT NULL,
 UNIQUE (laboratory_id, laboratory_order_id),
 FOREIGN KEY (laboratory_id, laboratory_order_id) REFERENCES laboratory_orders(laboratory_id,id) ON DELETE RESTRICT
);
CREATE TABLE laboratory_order_cash_operations (
 id char(26) PRIMARY KEY, laboratory_id bigint NOT NULL, laboratory_order_id bigint NOT NULL,
 operation_type varchar(30) NOT NULL CHECK (operation_type='record_payment'),
 key_hash char(64) NOT NULL CHECK (key_hash ~ '^[a-f0-9]{64}$'), request_hash char(64) NOT NULL CHECK (request_hash ~ '^[a-f0-9]{64}$'),
 created_by bigint NOT NULL, recorded_at timestamptz(6) NOT NULL, http_status integer NOT NULL CHECK (http_status=201),
 response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot)='object'),
 UNIQUE (laboratory_id, operation_type, key_hash), UNIQUE (laboratory_id,laboratory_order_id,id),
 FOREIGN KEY (laboratory_id,laboratory_order_id) REFERENCES laboratory_orders(laboratory_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (laboratory_id,created_by) REFERENCES laboratory_user(laboratory_id,user_id) ON DELETE RESTRICT
);
CREATE TABLE laboratory_order_cash_movements (
 id bigserial PRIMARY KEY, laboratory_id bigint NOT NULL, laboratory_order_id bigint NOT NULL,
 operation_id char(26) NOT NULL UNIQUE, financial_revision integer NOT NULL CHECK (financial_revision>1),
 type varchar(20) NOT NULL CHECK (type='payment'), amount numeric(12,2) NOT NULL CHECK (amount>0), currency char(3) NOT NULL,
 method varchar(20) NOT NULL CHECK (method IN ('cash','card','bank_transfer')), reference varchar(150),
 reason varchar(1000) CHECK (reason IS NULL), target_payment_id bigint CHECK (target_payment_id IS NULL),
 created_by bigint NOT NULL, recorded_at timestamptz(6) NOT NULL,
 CHECK (reference IS NULL OR (length(btrim(reference))>0 AND reference=btrim(reference))),
 CHECK (method='cash' OR reference IS NOT NULL), UNIQUE(laboratory_id,laboratory_order_id,id),
 FOREIGN KEY (laboratory_id,laboratory_order_id) REFERENCES laboratory_orders(laboratory_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (laboratory_id,created_by) REFERENCES laboratory_user(laboratory_id,user_id) ON DELETE RESTRICT,
 FOREIGN KEY (laboratory_id,laboratory_order_id,operation_id) REFERENCES laboratory_order_cash_operations(laboratory_id,laboratory_order_id,id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX cash_movement_order_revision ON laboratory_order_cash_movements(laboratory_id,laboratory_order_id,financial_revision DESC,id DESC);
CREATE TABLE laboratory_order_financial_revisions (
 id bigserial PRIMARY KEY, laboratory_id bigint NOT NULL, laboratory_order_id bigint NOT NULL,
 revision integer NOT NULL CHECK(revision>0), previous_revision integer,
 cause varchar(30) NOT NULL CHECK(cause IN ('initialized','payment_recorded','order_total_changed')),
 resource_type varchar(40), resource_id bigint, created_by bigint, recorded_at timestamptz(6) NOT NULL,
 total_snapshot numeric(12,2) NOT NULL CHECK(total_snapshot>=0),
 CHECK ((revision=1 AND previous_revision IS NULL AND cause='initialized') OR (revision>1 AND previous_revision=revision-1 AND cause<>'initialized')),
 CHECK ((cause='payment_recorded' AND resource_type='cash_movement' AND resource_id IS NOT NULL AND created_by IS NOT NULL) OR (cause<>'payment_recorded' AND resource_type IS NULL AND resource_id IS NULL)),
 UNIQUE(laboratory_id,laboratory_order_id,revision),
 FOREIGN KEY(laboratory_id,laboratory_order_id) REFERENCES laboratory_orders(laboratory_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(laboratory_id,created_by) REFERENCES laboratory_user(laboratory_id,user_id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION protect_cash_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'La evidencia financiera es inmutable' USING ERRCODE='23514'; END $$;
CREATE TRIGGER protect_cash_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW WHEN (OLD.event='cash.payment_recorded') EXECUTE FUNCTION protect_cash_history();
CREATE TRIGGER protect_cash_movements BEFORE UPDATE OR DELETE ON laboratory_order_cash_movements FOR EACH ROW EXECUTE FUNCTION protect_cash_history();
CREATE TRIGGER protect_cash_operations BEFORE UPDATE OR DELETE ON laboratory_order_cash_operations FOR EACH ROW EXECUTE FUNCTION protect_cash_history();
CREATE TRIGGER protect_financial_revisions BEFORE UPDATE OR DELETE ON laboratory_order_financial_revisions FOR EACH ROW EXECUTE FUNCTION protect_cash_history();
CREATE OR REPLACE FUNCTION validate_cash_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o laboratory_orders%ROWTYPE; s laboratory_order_financial_states%ROWTYPE;
BEGIN
 SELECT * INTO o FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR UPDATE;
 IF NOT FOUND OR o.delivery_policy_snapshot IS NULL THEN RAISE EXCEPTION 'Contexto financiero no disponible' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='laboratory_order_financial_states' THEN
  IF TG_OP='UPDATE' AND (NEW.laboratory_id<>OLD.laboratory_id OR NEW.laboratory_order_id<>OLD.laboratory_order_id OR NEW.revision<>OLD.revision+1 OR NEW.id<>OLD.id) THEN
   RAISE EXCEPTION 'Secuencia financiera inválida' USING ERRCODE='23514';
  END IF;
  IF TG_OP='INSERT' AND NEW.revision<>1 THEN RAISE EXCEPTION 'Revisión inicial inválida' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='laboratory_order_cash_movements' THEN
  SELECT * INTO s FROM laboratory_order_financial_states WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id FOR UPDATE;
  IF NOT FOUND OR NEW.financial_revision<>s.revision OR NEW.currency<>o.currency THEN RAISE EXCEPTION 'Moneda o revisión de cobro inválida' USING ERRCODE='23514'; END IF;
  IF (SELECT COALESCE(SUM(amount),0) FROM laboratory_order_cash_movements WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id)+NEW.amount>9999999999999999.99 THEN
   RAISE EXCEPTION 'Acumulado financiero fuera de rango' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validate_financial_state BEFORE INSERT OR UPDATE ON laboratory_order_financial_states FOR EACH ROW EXECUTE FUNCTION validate_cash_write();
CREATE TRIGGER protect_financial_state_delete BEFORE DELETE ON laboratory_order_financial_states FOR EACH ROW EXECUTE FUNCTION protect_cash_history();
CREATE TRIGGER validate_cash_movement BEFORE INSERT ON laboratory_order_cash_movements FOR EACH ROW EXECUTE FUNCTION validate_cash_write();
CREATE OR REPLACE FUNCTION validate_cash_aggregate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE lab bigint; ord bigint; s laboratory_order_financial_states%ROWTYPE; r laboratory_order_financial_revisions%ROWTYPE; m laboratory_order_cash_movements%ROWTYPE; op laboratory_order_cash_operations%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME='laboratory_orders' THEN lab:=NEW.laboratory_id; ord:=NEW.id; ELSE lab:=NEW.laboratory_id; ord:=NEW.laboratory_order_id; END IF;
 SELECT * INTO s FROM laboratory_order_financial_states WHERE laboratory_id=lab AND laboratory_order_id=ord;
 IF NOT FOUND THEN
  IF TG_TABLE_NAME='laboratory_orders' AND TG_OP='UPDATE' THEN RETURN NULL; END IF;
  RAISE EXCEPTION 'La orden nueva requiere estado financiero' USING ERRCODE='23514';
 END IF;
 SELECT * INTO r FROM laboratory_order_financial_revisions WHERE laboratory_id=lab AND laboratory_order_id=ord AND revision=s.revision;
 IF NOT FOUND OR s.updated_at IS DISTINCT FROM r.recorded_at OR r.total_snapshot IS DISTINCT FROM (SELECT total FROM laboratory_orders WHERE laboratory_id=lab AND id=ord)
 OR (SELECT count(*) FROM laboratory_order_financial_revisions WHERE laboratory_id=lab AND laboratory_order_id=ord)<>s.revision THEN
  RAISE EXCEPTION 'Estado e historial financiero inconsistentes' USING ERRCODE='23514';
 END IF;
 IF TG_TABLE_NAME='laboratory_order_financial_revisions' THEN
  r:=NEW;
  IF r.revision>s.revision THEN RAISE EXCEPTION 'Revisión financiera sin estado' USING ERRCODE='23514'; END IF;
  IF r.cause='initialized' AND r.created_by IS DISTINCT FROM (SELECT created_by FROM laboratory_orders WHERE laboratory_id=lab AND id=ord) THEN
   RAISE EXCEPTION 'Responsable de inicialización inválido' USING ERRCODE='23514';
  END IF;
  IF r.cause='payment_recorded' AND r.total_snapshot IS DISTINCT FROM (SELECT total_snapshot FROM laboratory_order_financial_revisions WHERE laboratory_id=lab AND laboratory_order_id=ord AND revision=r.previous_revision) THEN
   RAISE EXCEPTION 'Un cobro no cambia el total de la orden' USING ERRCODE='23514';
  END IF;
  IF r.cause='order_total_changed' AND r.total_snapshot IS NOT DISTINCT FROM (SELECT total_snapshot FROM laboratory_order_financial_revisions WHERE laboratory_id=lab AND laboratory_order_id=ord AND revision=r.previous_revision) THEN
   RAISE EXCEPTION 'Revisión sin cambio del total' USING ERRCODE='23514';
  END IF;
 END IF;
 IF TG_TABLE_NAME='laboratory_order_cash_movements' THEN
  m:=NEW;
 ELSIF TG_TABLE_NAME='laboratory_order_cash_operations' THEN
  SELECT * INTO m FROM laboratory_order_cash_movements WHERE operation_id=NEW.id;
  IF NOT FOUND THEN RAISE EXCEPTION 'Operación sin cobro' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='laboratory_order_financial_revisions' THEN
  IF NEW.cause<>'payment_recorded' THEN RETURN NULL; END IF;
  SELECT * INTO m FROM laboratory_order_cash_movements WHERE laboratory_id=lab AND laboratory_order_id=ord AND id=NEW.resource_id;
  IF NOT FOUND OR m.financial_revision<>NEW.revision THEN RAISE EXCEPTION 'Revisión sin cobro' USING ERRCODE='23514'; END IF;
 ELSE RETURN NULL;
 END IF;
 SELECT * INTO r FROM laboratory_order_financial_revisions WHERE laboratory_id=lab AND laboratory_order_id=ord AND revision=m.financial_revision;
 SELECT * INTO op FROM laboratory_order_cash_operations WHERE id=m.operation_id;
 IF r.cause IS DISTINCT FROM 'payment_recorded' OR r.resource_id IS DISTINCT FROM m.id OR r.created_by IS DISTINCT FROM m.created_by OR r.recorded_at IS DISTINCT FROM m.recorded_at
 OR op.laboratory_id IS DISTINCT FROM lab OR op.laboratory_order_id IS DISTINCT FROM ord OR op.created_by IS DISTINCT FROM m.created_by OR op.recorded_at IS DISTINCT FROM m.recorded_at
 OR op.response_snapshot->'data'->>'operation_id' IS DISTINCT FROM m.operation_id::text
 OR op.response_snapshot->'data'->>'operation' IS DISTINCT FROM 'record_payment'
 OR op.response_snapshot->'data'->>'laboratory_order_id' IS DISTINCT FROM ord::text
 OR op.response_snapshot->'data'->>'financial_revision' IS DISTINCT FROM m.financial_revision::text
 OR op.response_snapshot->'data'->'resource'->>'id' IS DISTINCT FROM m.id::text
 OR op.response_snapshot->'data'->'resource' IS DISTINCT FROM jsonb_build_object(
 'id',m.id,'financial_revision',m.financial_revision,'created_by',m.created_by,
 'recorded_at',to_char(m.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
 'type',m.type,'amount',m.amount::text,'currency',m.currency,'method',m.method,'reference',m.reference,
 'reason',NULL,'target_payment_id',NULL,'payment_status','active')
 OR NOT EXISTS(SELECT 1 FROM audit_logs WHERE laboratory_id=lab AND user_id=m.created_by AND event='cash.payment_recorded' AND auditable_type='cash_movement' AND auditable_id=m.id AND new_values=(op.response_snapshot->'data'->'resource') || jsonb_build_object('operation_id',m.operation_id,'laboratory_order_id',ord)) THEN
  RAISE EXCEPTION 'Cobro, operación, revisión y auditoría no concuerdan' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION protect_cash_order_currency() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.currency IS DISTINCT FROM OLD.currency AND EXISTS(SELECT 1 FROM laboratory_order_financial_states WHERE laboratory_id=OLD.laboratory_id AND laboratory_order_id=OLD.id) THEN
  RAISE EXCEPTION 'La moneda de una orden con contexto financiero es inmutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_cash_order_currency BEFORE UPDATE OF currency ON laboratory_orders FOR EACH ROW EXECUTE FUNCTION protect_cash_order_currency();
CREATE CONSTRAINT TRIGGER cash_order_initialized AFTER INSERT OR UPDATE OF total,currency ON laboratory_orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_cash_aggregate();
CREATE CONSTRAINT TRIGGER cash_state_consistent AFTER INSERT OR UPDATE ON laboratory_order_financial_states DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_cash_aggregate();
CREATE CONSTRAINT TRIGGER cash_revision_consistent AFTER INSERT ON laboratory_order_financial_revisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_cash_aggregate();
CREATE CONSTRAINT TRIGGER cash_movement_consistent AFTER INSERT ON laboratory_order_cash_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_cash_aggregate();
CREATE CONSTRAINT TRIGGER cash_operation_consistent AFTER INSERT ON laboratory_order_cash_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_cash_aggregate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('laboratory_order_financial_states')->exists()) {
            throw new RuntimeException('No se puede eliminar evidencia financiera existente.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER cash_order_initialized ON laboratory_orders;
DROP TRIGGER protect_cash_order_currency ON laboratory_orders;
DROP TRIGGER protect_cash_audit ON audit_logs;
DROP TABLE laboratory_order_cash_movements, laboratory_order_cash_operations, laboratory_order_financial_revisions, laboratory_order_financial_states;
DROP FUNCTION validate_cash_aggregate(), validate_cash_write(), protect_cash_history(), protect_cash_order_currency();
SQL);
    }
};
