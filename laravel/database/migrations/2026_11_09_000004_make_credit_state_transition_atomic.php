<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE laboratory_order_cash_operations DROP CONSTRAINT IF EXISTS cash_operation_types;
ALTER TABLE laboratory_order_cash_operations DROP CONSTRAINT IF EXISTS laboratory_order_cash_operations_operation_type_check;
ALTER TABLE laboratory_order_cash_operations ADD CONSTRAINT cash_operation_types CHECK(operation_type IN ('record_payment','void_payment','record_refund','authorize_credit','revoke_credit'));
ALTER TABLE laboratory_order_financial_revisions DROP CONSTRAINT IF EXISTS cash_revision_causes;
ALTER TABLE laboratory_order_financial_revisions DROP CONSTRAINT IF EXISTS cash_revision_resource;
ALTER TABLE laboratory_order_financial_revisions ADD CONSTRAINT cash_revision_causes CHECK(cause IN ('initialized','payment_recorded','payment_voided','refund_recorded','credit_authorized','credit_revoked','order_total_changed'));
ALTER TABLE laboratory_order_financial_revisions ADD CONSTRAINT cash_revision_resource CHECK ((cause IN ('payment_recorded','payment_voided','refund_recorded') AND resource_type='cash_movement' AND resource_id IS NOT NULL AND created_by IS NOT NULL) OR (cause IN ('credit_authorized','credit_revoked') AND resource_type='credit_event' AND resource_id IS NOT NULL AND created_by IS NOT NULL) OR (cause NOT IN ('payment_recorded','payment_voided','refund_recorded','credit_authorized','credit_revoked') AND resource_type IS NULL AND resource_id IS NULL));
DROP TRIGGER protect_cash_audit ON audit_logs;
CREATE TRIGGER protect_cash_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW WHEN (OLD.event IN ('cash.payment_recorded','cash.payment_voided','cash.refund_recorded','cash.credit_authorized','cash.credit_revoked')) EXECUTE FUNCTION protect_cash_history();
DROP TRIGGER cash_operation_consistent ON laboratory_order_cash_operations;
CREATE CONSTRAINT TRIGGER cash_operation_consistent AFTER INSERT ON laboratory_order_cash_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type IN ('record_payment','void_payment','record_refund')) EXECUTE FUNCTION validate_cash_aggregate();
CREATE OR REPLACE FUNCTION validate_cash_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o laboratory_orders%ROWTYPE; s laboratory_order_financial_states%ROWTYPE;
BEGIN
 SELECT * INTO o FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR UPDATE;
 IF NOT FOUND OR o.delivery_policy_snapshot IS NULL THEN RAISE EXCEPTION 'Contexto financiero no disponible' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='laboratory_order_financial_states' THEN
  IF TG_OP='UPDATE' AND (NEW.laboratory_id<>OLD.laboratory_id OR NEW.laboratory_order_id<>OLD.laboratory_order_id OR NEW.id<>OLD.id OR NEW.revision<>OLD.revision+1) THEN RAISE EXCEPTION 'Secuencia financiera inválida' USING ERRCODE='23514'; END IF;
  IF TG_OP='INSERT' AND NEW.revision<>1 THEN RAISE EXCEPTION 'Revisión inicial inválida' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='laboratory_order_cash_movements' THEN
  SELECT * INTO s FROM laboratory_order_financial_states WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id FOR UPDATE;
  IF NOT FOUND OR NEW.financial_revision<>s.revision OR NEW.currency<>o.currency THEN RAISE EXCEPTION 'Moneda o revisión de cobro inválida' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION validate_credit_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s laboratory_order_financial_states%ROWTYPE; o laboratory_orders%ROWTYPE; target laboratory_order_credit_events%ROWTYPE;
BEGIN
 SELECT * INTO o FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR UPDATE;
 SELECT * INTO s FROM laboratory_order_financial_states WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id FOR UPDATE;
 IF NOT FOUND OR NEW.financial_revision<>s.revision THEN RAISE EXCEPTION 'Revisión de crédito inválida' USING ERRCODE='23514'; END IF;
 IF NEW.type='authorized' THEN
  IF o.delivery_policy_snapshot->>'mode' <> 'payment_or_credit' THEN RAISE EXCEPTION 'Política de crédito incompatible' USING ERRCODE='23514'; END IF;
  IF NEW.amount < GREATEST(o.total - (SELECT COALESCE(SUM(CASE WHEN m.type='payment' AND NOT EXISTS (SELECT 1 FROM laboratory_order_cash_movements v WHERE v.laboratory_id=m.laboratory_id AND v.laboratory_order_id=m.laboratory_order_id AND v.type='payment_void' AND v.target_payment_id=m.id) THEN m.amount WHEN m.type='refund' THEN -m.amount ELSE 0 END),0) FROM laboratory_order_cash_movements m WHERE m.laboratory_id=NEW.laboratory_id AND m.laboratory_order_id=NEW.laboratory_order_id),0) THEN RAISE EXCEPTION 'Crédito insuficiente' USING ERRCODE='23514'; END IF;
 ELSIF s.active_credit_authorization_id IS NOT NULL THEN
  RAISE EXCEPTION 'Crédito activo no revocado atómicamente' USING ERRCODE='23514';
 ELSE
  SELECT * INTO target FROM laboratory_order_credit_events WHERE id=NEW.target_authorization_id AND laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id AND type='authorized';
  IF NOT FOUND OR target.id IS DISTINCT FROM (SELECT candidate.id FROM laboratory_order_credit_events candidate WHERE candidate.laboratory_id=NEW.laboratory_id AND candidate.laboratory_order_id=NEW.laboratory_order_id AND candidate.type='authorized' AND NOT EXISTS (SELECT 1 FROM laboratory_order_credit_events revoked WHERE revoked.type='revoked' AND revoked.target_authorization_id=candidate.id) ORDER BY candidate.financial_revision DESC, candidate.id DESC LIMIT 1) THEN RAISE EXCEPTION 'Crédito no activo' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La corrección de integridad financiera no admite reversión automática.');
    }
};
