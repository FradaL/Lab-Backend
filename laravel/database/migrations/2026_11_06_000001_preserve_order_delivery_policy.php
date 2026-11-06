<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            $table->jsonb('delivery_policy_snapshot')->nullable();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION preserve_order_delivery_policy() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 snapshot jsonb;
 policy report_delivery_policies%ROWTYPE;
 history report_delivery_policy_changes%ROWTYPE;
BEGIN
 IF TG_OP='UPDATE' THEN
  IF NEW.delivery_policy_snapshot IS DISTINCT FROM OLD.delivery_policy_snapshot
     OR (OLD.delivery_policy_snapshot IS NOT NULL AND NEW.laboratory_id IS DISTINCT FROM OLD.laboratory_id) THEN
   RAISE EXCEPTION 'La política original de la orden es inmutable' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
 END IF;
 snapshot := NEW.delivery_policy_snapshot;
 IF snapshot IS NULL OR jsonb_typeof(snapshot) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'La nueva orden requiere una política explícita' USING ERRCODE='23514';
 END IF;
 IF NOT (snapshot ?& ARRAY['mode','source_revision','source','configured_by','configured_at','captured_at'])
    OR snapshot - ARRAY['mode','source_revision','source','configured_by','configured_at','captured_at'] <> '{}'::jsonb
    OR jsonb_typeof(snapshot->'mode') IS DISTINCT FROM 'string'
    OR jsonb_typeof(snapshot->'source') IS DISTINCT FROM 'string'
    OR snapshot->>'source' IS DISTINCT FROM 'configured'
    OR jsonb_typeof(snapshot->'source_revision') IS DISTINCT FROM 'number'
    OR (snapshot->>'source_revision') !~ '^[1-9][0-9]*$'
    OR jsonb_typeof(snapshot->'configured_by') IS DISTINCT FROM 'number'
    OR (snapshot->>'configured_by') !~ '^[1-9][0-9]*$'
    OR jsonb_typeof(snapshot->'configured_at') IS DISTINCT FROM 'string'
    OR jsonb_typeof(snapshot->'captured_at') IS DISTINCT FROM 'string'
    OR (snapshot->>'configured_at') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$'
    OR (snapshot->>'captured_at') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$' THEN
  RAISE EXCEPTION 'Snapshot de política inválido' USING ERRCODE='23514';
 END IF;
 PERFORM 1 FROM laboratories WHERE id=NEW.laboratory_id FOR SHARE;
 SELECT * INTO policy FROM report_delivery_policies WHERE laboratory_id=NEW.laboratory_id FOR SHARE;
 IF NOT FOUND OR policy.mode IS DISTINCT FROM snapshot->>'mode'
    OR policy.revision::text IS DISTINCT FROM snapshot->>'source_revision'
    OR policy.updated_by::text IS DISTINCT FROM snapshot->>'configured_by'
    OR policy.updated_at IS DISTINCT FROM (snapshot->>'configured_at')::timestamptz THEN
  RAISE EXCEPTION 'La política no corresponde a la configuración vigente del laboratorio' USING ERRCODE='23514';
 END IF;
 SELECT * INTO history FROM report_delivery_policy_changes
  WHERE laboratory_id=NEW.laboratory_id AND revision=policy.revision FOR SHARE;
 IF NOT FOUND OR history.new_mode IS DISTINCT FROM policy.mode
    OR history.changed_by IS DISTINCT FROM policy.updated_by
    OR history.changed_at IS DISTINCT FROM policy.updated_at
    OR (snapshot->>'captured_at')::timestamptz < policy.updated_at THEN
  RAISE EXCEPTION 'La política requiere procedencia verificable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER preserve_order_delivery_policy BEFORE INSERT OR UPDATE ON laboratory_orders
 FOR EACH ROW EXECUTE FUNCTION preserve_order_delivery_policy();
SQL);
    }

    public function down(): void
    {
        if (DB::table('laboratory_orders')->whereNotNull('delivery_policy_snapshot')->exists()) {
            throw new RuntimeException('No se puede eliminar la evidencia de políticas guardadas en órdenes.');
        }
        DB::unprepared('DROP TRIGGER preserve_order_delivery_policy ON laboratory_orders; DROP FUNCTION preserve_order_delivery_policy();');
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_policy_snapshot');
        });
    }
};
