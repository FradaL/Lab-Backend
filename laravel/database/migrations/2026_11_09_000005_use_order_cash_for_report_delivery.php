<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_report_delivery_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p report_delivery_preparations%ROWTYPE; snapshot jsonb; current_revision integer; current_mode text;
BEGIN
 SELECT delivery_policy_snapshot INTO snapshot FROM laboratory_orders WHERE laboratory_id=NEW.laboratory_id AND id=NEW.laboratory_order_id FOR SHARE;
 IF snapshot IS NULL OR jsonb_typeof(snapshot)<>'object' OR snapshot->>'mode' NOT IN ('unrestricted','full_payment_required','payment_or_credit') OR (snapshot->>'source_revision') !~ '^[1-9][0-9]*$' THEN RAISE EXCEPTION 'Delivery requires order policy snapshot' USING ERRCODE='23514'; END IF;
 current_revision:=(snapshot->>'source_revision')::integer; current_mode:=snapshot->>'mode';
 IF NEW.policy_revision<>current_revision OR NEW.policy_mode<>current_mode THEN RAISE EXCEPTION 'Delivery requires order policy' USING ERRCODE='23514'; END IF;
 IF NEW.evaluation_snapshot->>'provider'<>'order_cash_v1' OR NEW.evaluation_snapshot->>'evidence_reference'<>NEW.laboratory_order_id::text OR NEW.evaluation_snapshot->>'fingerprint' !~ '^[a-f0-9]{64}$' OR NEW.evaluation_snapshot->>'status' NOT IN ('satisfied','not_satisfied','unavailable') THEN RAISE EXCEPTION 'Delivery requires typed order cash evaluation' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='report_delivery_preparations' THEN
  IF report_commercial_satisfied(NEW.policy_mode,NEW.evaluation_snapshot) THEN IF NEW.exception_authorization_id IS NOT NULL THEN RAISE EXCEPTION 'Satisfied delivery cannot consume exception' USING ERRCODE='23514'; END IF;
  ELSE PERFORM 1 FROM report_delivery_exceptions x JOIN report_delivery_exception_authorizations y ON y.exception_id=x.id WHERE y.id=NEW.exception_authorization_id AND x.laboratory_id=NEW.laboratory_id AND x.laboratory_order_id=NEW.laboratory_order_id AND x.report_id=NEW.report_id AND x.status='authorized' AND y.policy_revision=NEW.policy_revision AND y.policy_mode=NEW.policy_mode AND y.evaluation_snapshot->>'fingerprint'=NEW.evaluation_snapshot->>'fingerprint'; IF NOT FOUND THEN RAISE EXCEPTION 'Delivery requires exact active commercial authorization' USING ERRCODE='23514'; END IF; END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO p FROM report_delivery_preparations WHERE id=NEW.preparation_id FOR UPDATE;
 IF NOT FOUND OR p.laboratory_id<>NEW.laboratory_id OR p.laboratory_order_id<>NEW.laboratory_order_id OR p.report_id<>NEW.report_id OR p.downloaded_at IS NULL OR p.expires_at<=NEW.delivered_at OR p.channel<>NEW.channel OR p.recipient_snapshot IS DISTINCT FROM NEW.recipient_snapshot OR p.policy_revision<>NEW.policy_revision OR p.policy_mode<>NEW.policy_mode OR p.evaluation_snapshot IS DISTINCT FROM NEW.evaluation_snapshot OR p.exception_authorization_id IS DISTINCT FROM NEW.exception_authorization_id THEN RAISE EXCEPTION 'Delivery requires exact downloaded unexpired preparation' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void { throw new LogicException('Delivery evidence migration requires an explicit restore strategy.'); }
};
