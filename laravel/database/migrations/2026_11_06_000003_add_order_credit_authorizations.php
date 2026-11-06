<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE laboratory_order_credit_events (
 id bigserial PRIMARY KEY, laboratory_id bigint NOT NULL, laboratory_order_id bigint NOT NULL,
 operation_id char(26) NOT NULL UNIQUE, financial_revision integer NOT NULL CHECK (financial_revision > 1),
 type varchar(20) NOT NULL CHECK (type IN ('authorized','revoked')), amount numeric(12,2),
 reason varchar(1000) NOT NULL CHECK (length(btrim(reason)) > 0 AND reason=btrim(reason)),
 target_authorization_id bigint, replaces_authorization_id bigint, created_by bigint NOT NULL, recorded_at timestamptz(6) NOT NULL,
 CHECK ((type='authorized' AND amount > 0 AND target_authorization_id IS NULL) OR (type='revoked' AND amount IS NULL AND target_authorization_id IS NOT NULL)),
 UNIQUE(laboratory_id,laboratory_order_id,id),
 FOREIGN KEY(laboratory_id,laboratory_order_id) REFERENCES laboratory_orders(laboratory_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(laboratory_id,created_by) REFERENCES laboratory_user(laboratory_id,user_id) ON DELETE RESTRICT,
 FOREIGN KEY(laboratory_id,laboratory_order_id,operation_id) REFERENCES laboratory_order_cash_operations(laboratory_id,laboratory_order_id,id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(laboratory_id,laboratory_order_id,target_authorization_id) REFERENCES laboratory_order_credit_events(laboratory_id,laboratory_order_id,id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(laboratory_id,laboratory_order_id,replaces_authorization_id) REFERENCES laboratory_order_credit_events(laboratory_id,laboratory_order_id,id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED
);
CREATE UNIQUE INDEX credit_event_one_revocation ON laboratory_order_credit_events(laboratory_id,target_authorization_id) WHERE type='revoked';
CREATE INDEX credit_event_order_revision ON laboratory_order_credit_events(laboratory_id,laboratory_order_id,financial_revision DESC,id DESC);
ALTER TABLE laboratory_order_cash_operations DROP CONSTRAINT laboratory_order_cash_operations_operation_type_check;
ALTER TABLE laboratory_order_cash_operations ADD CONSTRAINT laboratory_order_cash_operations_operation_type_check CHECK (operation_type IN ('record_payment','authorize_credit','revoke_credit'));
ALTER TABLE laboratory_order_financial_revisions DROP CONSTRAINT laboratory_order_financial_revisions_cause_check;
ALTER TABLE laboratory_order_financial_revisions ADD CONSTRAINT laboratory_order_financial_revisions_cause_check CHECK (cause IN ('initialized','payment_recorded','credit_authorized','credit_revoked','order_total_changed'));
ALTER TABLE laboratory_order_financial_revisions DROP CONSTRAINT laboratory_order_financial_revisions_check1;
ALTER TABLE laboratory_order_financial_revisions ADD CONSTRAINT laboratory_order_financial_revisions_check1 CHECK ((cause='payment_recorded' AND resource_type='cash_movement' AND resource_id IS NOT NULL AND created_by IS NOT NULL) OR (cause IN ('credit_authorized','credit_revoked') AND resource_type='credit_event' AND resource_id IS NOT NULL AND created_by IS NOT NULL) OR (cause NOT IN ('payment_recorded','credit_authorized','credit_revoked') AND resource_type IS NULL AND resource_id IS NULL));
ALTER TABLE laboratory_order_financial_states DROP CONSTRAINT laboratory_order_financial_s_active_credit_authorization__check;
ALTER TABLE laboratory_order_financial_states ADD CONSTRAINT financial_state_active_credit_fk FOREIGN KEY(laboratory_id,laboratory_order_id,active_credit_authorization_id) REFERENCES laboratory_order_credit_events(laboratory_id,laboratory_order_id,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TRIGGER protect_credit_events BEFORE UPDATE OR DELETE ON laboratory_order_credit_events FOR EACH ROW EXECUTE FUNCTION protect_cash_history();
DROP TRIGGER cash_operation_consistent ON laboratory_order_cash_operations;
CREATE CONSTRAINT TRIGGER cash_operation_consistent AFTER INSERT ON laboratory_order_cash_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type='record_payment') EXECUTE FUNCTION validate_cash_aggregate();
CREATE OR REPLACE FUNCTION validate_credit_operation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM laboratory_order_credit_events WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id AND operation_id=NEW.id) THEN RAISE EXCEPTION 'Operación sin crédito' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER credit_operation_consistent AFTER INSERT ON laboratory_order_cash_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type IN ('authorize_credit','revoke_credit')) EXECUTE FUNCTION validate_credit_operation();
CREATE OR REPLACE FUNCTION validate_cash_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o laboratory_orders%ROWTYPE; s laboratory_order_financial_states%ROWTYPE;
BEGIN
 SELECT * INTO o FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR UPDATE;
 IF NOT FOUND OR o.delivery_policy_snapshot IS NULL THEN RAISE EXCEPTION 'Contexto financiero no disponible' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='laboratory_order_financial_states' THEN
  IF TG_OP='UPDATE' AND (NEW.laboratory_id<>OLD.laboratory_id OR NEW.laboratory_order_id<>OLD.laboratory_order_id OR NEW.id<>OLD.id OR NOT ((NEW.revision=OLD.revision+1) OR (NEW.revision=OLD.revision AND NEW.active_credit_authorization_id IS DISTINCT FROM OLD.active_credit_authorization_id))) THEN RAISE EXCEPTION 'Secuencia financiera inválida' USING ERRCODE='23514'; END IF;
  IF TG_OP='INSERT' AND NEW.revision<>1 THEN RAISE EXCEPTION 'Revisión inicial inválida' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='laboratory_order_cash_movements' THEN
  SELECT * INTO s FROM laboratory_order_financial_states WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id FOR UPDATE;
  IF NOT FOUND OR NEW.financial_revision<>s.revision OR NEW.currency<>o.currency THEN RAISE EXCEPTION 'Moneda o revisión de cobro inválida' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION validate_credit_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s laboratory_order_financial_states%ROWTYPE; o laboratory_orders%ROWTYPE;
BEGIN
 SELECT * INTO o FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR UPDATE;
 SELECT * INTO s FROM laboratory_order_financial_states WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id FOR UPDATE;
 IF NOT FOUND OR NEW.financial_revision<>s.revision THEN RAISE EXCEPTION 'Revisión de crédito inválida' USING ERRCODE='23514'; END IF;
 IF NEW.type='authorized' THEN
  IF o.delivery_policy_snapshot->>'mode' <> 'payment_or_credit' THEN RAISE EXCEPTION 'Política de crédito incompatible' USING ERRCODE='23514'; END IF;
  IF NEW.amount < GREATEST(o.total-(SELECT COALESCE(SUM(amount),0) FROM laboratory_order_cash_movements WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id AND type='payment'),0) THEN RAISE EXCEPTION 'Crédito insuficiente' USING ERRCODE='23514'; END IF;
 ELSIF NEW.target_authorization_id IS DISTINCT FROM s.active_credit_authorization_id THEN RAISE EXCEPTION 'Crédito no activo' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validate_credit_event BEFORE INSERT ON laboratory_order_credit_events FOR EACH ROW EXECUTE FUNCTION validate_credit_write();
SQL);
    }

    public function down(): void
    {
        if (DB::table('laboratory_order_credit_events')->exists()) {
            throw new RuntimeException('No se puede eliminar evidencia de crédito existente.');
        }
        DB::unprepared('DROP TRIGGER protect_credit_events ON laboratory_order_credit_events; DROP TRIGGER validate_credit_event ON laboratory_order_credit_events; DROP FUNCTION validate_credit_write(); ALTER TABLE laboratory_order_financial_states DROP CONSTRAINT financial_state_active_credit_fk; DROP TABLE laboratory_order_credit_events;');
    }
};
