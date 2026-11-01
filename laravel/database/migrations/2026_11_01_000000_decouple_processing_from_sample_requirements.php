<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_sample_processing() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v jsonb;
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.processing_started_at IS NOT NULL OR NEW.processing_started_by IS NOT NULL THEN RAISE EXCEPTION 'A line must be composed before explicit processing start' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id IS DISTINCT FROM OLD.id THEN RAISE EXCEPTION 'Line identity is immutable' USING ERRCODE = '23514'; END IF;
 IF OLD.processing_started_at IS NULL AND NEW.processing_started_at IS NOT NULL THEN
  SELECT configuration INTO v FROM laboratory_exam_versions WHERE id = NEW.clinical_version_id AND status = 'published';
  IF v IS NULL OR NOT EXISTS (SELECT 1 FROM laboratory_orders WHERE id = NEW.laboratory_order_id AND status IN ('pending', 'in_process'))
   THEN RAISE EXCEPTION 'Processing start requires a published clinical version and an open order' USING ERRCODE = '23514'; END IF;
 END IF;
 IF OLD.processing_started_at IS NOT NULL AND (NEW.processing_started_at, NEW.processing_started_by) IS DISTINCT FROM (OLD.processing_started_at, OLD.processing_started_by)
 THEN RAISE EXCEPTION 'Processing start cannot be erased or changed' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_sample_processing() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v jsonb;
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.processing_started_at IS NOT NULL OR NEW.processing_started_by IS NOT NULL THEN RAISE EXCEPTION 'A line must be composed before explicit processing start' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id IS DISTINCT FROM OLD.id THEN RAISE EXCEPTION 'Line identity is immutable' USING ERRCODE = '23514'; END IF;
 IF OLD.processing_started_at IS NULL AND NEW.processing_started_at IS NOT NULL THEN
  SELECT configuration INTO v FROM laboratory_exam_versions WHERE id = NEW.clinical_version_id AND status = 'published';
  IF v IS NULL OR NOT EXISTS (SELECT 1 FROM laboratory_orders WHERE id = NEW.laboratory_order_id AND status IN ('pending', 'in_process'))
   OR (SELECT count(*) FROM laboratory_order_sample_requirements WHERE laboratory_order_exam_id = NEW.id) <> jsonb_array_length(coalesce(v->'sample_requirements', '[]'::jsonb))
   OR EXISTS (SELECT 1 FROM laboratory_order_sample_requirements r WHERE r.laboratory_order_exam_id = NEW.id
    AND coalesce((r.configuration->>'mandatory')::boolean, true) AND NOT EXISTS (
     SELECT 1 FROM laboratory_sample_processing_dependencies d JOIN laboratory_sample_associations a ON a.id = d.association_id
     JOIN laboratory_samples s ON s.id = a.laboratory_sample_id WHERE d.laboratory_order_exam_id = NEW.id AND a.sample_requirement_id = r.id
      AND a.released_at IS NULL AND s.status = 'accepted'
      AND sample_compatibility_errors(r.configuration, to_jsonb(s), (v->>'schema_version')::integer, sample_operation_time()) = '[]'::jsonb
    )) THEN RAISE EXCEPTION 'Processing start requires published complete and satisfied historical requirements' USING ERRCODE = '23514'; END IF;
 END IF;
 IF OLD.processing_started_at IS NOT NULL AND (NEW.processing_started_at, NEW.processing_started_by) IS DISTINCT FROM (OLD.processing_started_at, OLD.processing_started_by)
 THEN RAISE EXCEPTION 'Processing start cannot be erased or changed' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
SQL);
    }
};
