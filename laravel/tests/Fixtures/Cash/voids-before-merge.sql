-- Esquema histórico previo a integrar BE-D02-11 y BE-D02-12. Solo para pruebas aisladas.
DO $$
DECLARE c record;
BEGIN
 FOR c IN SELECT conname,conrelid::regclass AS tbl FROM pg_constraint WHERE contype='c' AND (
 (conrelid='laboratory_order_cash_operations'::regclass AND pg_get_constraintdef(oid) LIKE '%operation_type%') OR
 (conrelid='laboratory_order_cash_movements'::regclass AND pg_get_constraintdef(oid) ~ '(type|method|reference|reason|target_payment_id)') OR
 (conrelid='laboratory_order_financial_revisions'::regclass AND pg_get_constraintdef(oid) LIKE '%cause%'))
 LOOP EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I',c.tbl,c.conname); END LOOP;
END $$;
ALTER TABLE laboratory_order_cash_operations ADD CONSTRAINT cash_operation_type_v11 CHECK(operation_type IN ('record_payment','void_payment'));
ALTER TABLE laboratory_order_cash_movements ALTER COLUMN method DROP NOT NULL;
ALTER TABLE laboratory_order_cash_movements ADD CONSTRAINT cash_movement_shape_v11 CHECK (
 (type='payment' AND method IS NOT NULL AND method IN ('cash','card','bank_transfer') AND reason IS NULL AND target_payment_id IS NULL AND (method='cash' OR reference IS NOT NULL)
 AND (reference IS NULL OR (length(btrim(reference))>0 AND reference=btrim(reference)))) OR
 (type='payment_void' AND method IS NULL AND reference IS NULL AND reason IS NOT NULL AND length(btrim(reason))>0 AND reason=btrim(reason) AND target_payment_id IS NOT NULL)
);
ALTER TABLE laboratory_order_cash_movements ADD CONSTRAINT cash_void_target_v11 FOREIGN KEY(laboratory_id,laboratory_order_id,target_payment_id) REFERENCES laboratory_order_cash_movements(laboratory_id,laboratory_order_id,id) ON DELETE RESTRICT;
CREATE UNIQUE INDEX cash_one_void_per_payment ON laboratory_order_cash_movements(laboratory_id,target_payment_id) WHERE type='payment_void';
ALTER TABLE laboratory_order_financial_revisions ADD CONSTRAINT cash_revision_cause_v11 CHECK(cause IN ('initialized','payment_recorded','payment_voided','order_total_changed'));
ALTER TABLE laboratory_order_financial_revisions ADD CONSTRAINT cash_revision_sequence_v11 CHECK ((revision=1 AND previous_revision IS NULL AND cause='initialized') OR (revision>1 AND previous_revision=revision-1 AND cause<>'initialized'));
ALTER TABLE laboratory_order_financial_revisions ADD CONSTRAINT cash_revision_resource_v11 CHECK ((cause IN ('payment_recorded','payment_voided') AND resource_type='cash_movement' AND resource_id IS NOT NULL AND created_by IS NOT NULL) OR (cause NOT IN ('payment_recorded','payment_voided') AND resource_type IS NULL AND resource_id IS NULL));
DROP TRIGGER protect_cash_audit ON audit_logs;
CREATE TRIGGER protect_cash_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW WHEN (OLD.event IN ('cash.payment_recorded','cash.payment_voided')) EXECUTE FUNCTION protect_cash_history();
CREATE OR REPLACE FUNCTION validate_cash_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o laboratory_orders%ROWTYPE; s laboratory_order_financial_states%ROWTYPE; p laboratory_order_cash_movements%ROWTYPE; net numeric;
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
  IF NEW.type='payment' AND (SELECT COALESCE(SUM(amount),0) FROM laboratory_order_cash_movements WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id AND type='payment')+NEW.amount>9999999999999999.99 THEN
   RAISE EXCEPTION 'Acumulado financiero fuera de rango' USING ERRCODE='23514';
  END IF;
  IF NEW.type='payment_void' THEN
   SELECT * INTO p FROM laboratory_order_cash_movements WHERE laboratory_id=NEW.laboratory_id AND laboratory_order_id=NEW.laboratory_order_id AND id=NEW.target_payment_id;
   IF NOT FOUND OR p.type<>'payment' OR p.amount<>NEW.amount OR p.currency<>NEW.currency THEN
    RAISE EXCEPTION 'Destino o importe de anulación inválido' USING ERRCODE='23514';
   END IF;
   SELECT COALESCE(SUM(CASE WHEN m.type='payment' AND NOT EXISTS (
    SELECT 1 FROM laboratory_order_cash_movements v WHERE v.laboratory_id=m.laboratory_id AND v.laboratory_order_id=m.laboratory_order_id AND v.type='payment_void' AND v.target_payment_id=m.id
   ) THEN m.amount WHEN m.type='refund' THEN -m.amount ELSE 0 END),0) INTO net
   FROM laboratory_order_cash_movements m WHERE m.laboratory_id=NEW.laboratory_id AND m.laboratory_order_id=NEW.laboratory_order_id;
   IF net-NEW.amount<0 THEN RAISE EXCEPTION 'La anulación produciría pagos netos negativos' USING ERRCODE='23514'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
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
  IF r.cause IN ('payment_recorded','payment_voided') AND r.total_snapshot IS DISTINCT FROM (SELECT total_snapshot FROM laboratory_order_financial_revisions WHERE laboratory_id=lab AND laboratory_order_id=ord AND revision=r.previous_revision) THEN
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
  IF NEW.cause NOT IN ('payment_recorded','payment_voided') THEN RETURN NULL; END IF;
  SELECT * INTO m FROM laboratory_order_cash_movements WHERE laboratory_id=lab AND laboratory_order_id=ord AND id=NEW.resource_id;
  IF NOT FOUND OR m.financial_revision<>NEW.revision THEN RAISE EXCEPTION 'Revisión sin cobro' USING ERRCODE='23514'; END IF;
 ELSE RETURN NULL;
 END IF;
 SELECT * INTO r FROM laboratory_order_financial_revisions WHERE laboratory_id=lab AND laboratory_order_id=ord AND revision=m.financial_revision;
 SELECT * INTO op FROM laboratory_order_cash_operations WHERE id=m.operation_id;
 IF r.cause IS DISTINCT FROM (CASE WHEN m.type='payment' THEN 'payment_recorded' ELSE 'payment_voided' END) OR r.resource_id IS DISTINCT FROM m.id OR r.created_by IS DISTINCT FROM m.created_by OR r.recorded_at IS DISTINCT FROM m.recorded_at
 OR op.laboratory_id IS DISTINCT FROM lab OR op.laboratory_order_id IS DISTINCT FROM ord OR op.created_by IS DISTINCT FROM m.created_by OR op.recorded_at IS DISTINCT FROM m.recorded_at
 OR op.response_snapshot->'data'->>'operation_id' IS DISTINCT FROM m.operation_id::text
 OR op.operation_type IS DISTINCT FROM (CASE WHEN m.type='payment' THEN 'record_payment' ELSE 'void_payment' END)
 OR op.response_snapshot->'data'->>'operation' IS DISTINCT FROM op.operation_type
 OR op.response_snapshot->'data'->>'laboratory_order_id' IS DISTINCT FROM ord::text
 OR op.response_snapshot->'data'->>'financial_revision' IS DISTINCT FROM m.financial_revision::text
 OR op.response_snapshot->'data'->'resource'->>'id' IS DISTINCT FROM m.id::text
 OR op.response_snapshot->'data'->'resource' IS DISTINCT FROM jsonb_build_object(
 'id',m.id,'financial_revision',m.financial_revision,'created_by',m.created_by,
 'recorded_at',to_char(m.recorded_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
 'type',m.type,'amount',m.amount::text,'currency',m.currency,'method',m.method,'reference',m.reference,
 'reason',m.reason,'target_payment_id',m.target_payment_id,'payment_status',CASE WHEN m.type='payment' THEN 'active' ELSE NULL END)
 OR NOT EXISTS(SELECT 1 FROM audit_logs WHERE laboratory_id=lab AND user_id=m.created_by AND event=CASE WHEN m.type='payment' THEN 'cash.payment_recorded' ELSE 'cash.payment_voided' END AND auditable_type='cash_movement' AND auditable_id=m.id AND new_values=(op.response_snapshot->'data'->'resource') || jsonb_build_object('operation_id',m.operation_id,'laboratory_order_id',ord)) THEN
  RAISE EXCEPTION 'Cobro, operación, revisión y auditoría no concuerdan' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END $$;
