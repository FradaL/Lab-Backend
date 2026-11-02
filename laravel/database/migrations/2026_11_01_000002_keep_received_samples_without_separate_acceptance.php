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
CREATE OR REPLACE VIEW laboratory_sample_requirement_satisfactions AS
 SELECT r.id AS sample_requirement_id, r.laboratory_id, r.laboratory_order_id,
 CASE WHEN EXISTS (
  SELECT 1 FROM laboratory_sample_associations a JOIN laboratory_samples s ON s.id = a.laboratory_sample_id
  JOIN laboratory_exam_versions v ON v.id = r.clinical_version_id
  WHERE a.sample_requirement_id = r.id AND a.released_at IS NULL AND s.status IN ('received', 'accepted')
   AND sample_compatibility_errors(r.configuration, to_jsonb(s), (v.configuration->>'schema_version')::integer, sample_operation_time()) = '[]'::jsonb
 ) THEN 'satisfied' ELSE 'pending' END AS status FROM laboratory_order_sample_requirements r;
CREATE OR REPLACE FUNCTION protect_sample_dependency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a laboratory_sample_associations; r laboratory_order_sample_requirements; s laboratory_samples; v jsonb;
BEGIN
 SELECT * INTO a FROM laboratory_sample_associations WHERE id = NEW.association_id;
 SELECT * INTO r FROM laboratory_order_sample_requirements WHERE id = a.sample_requirement_id;
 SELECT * INTO s FROM laboratory_samples WHERE id = a.laboratory_sample_id;
 SELECT configuration INTO v FROM laboratory_exam_versions WHERE id = r.clinical_version_id;
 IF r.laboratory_order_exam_id IS DISTINCT FROM NEW.laboratory_order_exam_id OR a.released_at IS NOT NULL OR s.status NOT IN ('received', 'accepted')
  OR sample_compatibility_errors(r.configuration, to_jsonb(s), (v->>'schema_version')::integer, sample_operation_time()) <> '[]'::jsonb
 THEN RAISE EXCEPTION 'A processing dependency requires an adequate active received or historical accepted association on its own line' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION prevent_separate_sample_acceptance() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status = 'accepted' AND OLD.status IS DISTINCT FROM 'accepted'
 THEN RAISE EXCEPTION 'Invalid physical sample transition: reception already records acceptance' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER prevent_separate_sample_acceptance BEFORE UPDATE ON laboratory_samples FOR EACH ROW EXECUTE FUNCTION prevent_separate_sample_acceptance();
SQL);
        $this->updateReplacementAcceptance(true);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS prevent_separate_sample_acceptance ON laboratory_samples;
DROP FUNCTION IF EXISTS prevent_separate_sample_acceptance();
CREATE OR REPLACE VIEW laboratory_sample_requirement_satisfactions AS
 SELECT r.id AS sample_requirement_id, r.laboratory_id, r.laboratory_order_id,
 CASE WHEN EXISTS (
  SELECT 1 FROM laboratory_sample_associations a JOIN laboratory_samples s ON s.id = a.laboratory_sample_id
  JOIN laboratory_exam_versions v ON v.id = r.clinical_version_id
  WHERE a.sample_requirement_id = r.id AND a.released_at IS NULL AND s.status = 'accepted'
   AND sample_compatibility_errors(r.configuration, to_jsonb(s), (v.configuration->>'schema_version')::integer, sample_operation_time()) = '[]'::jsonb
 ) THEN 'satisfied' ELSE 'pending' END AS status FROM laboratory_order_sample_requirements r;
CREATE OR REPLACE FUNCTION protect_sample_dependency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a laboratory_sample_associations; r laboratory_order_sample_requirements; s laboratory_samples; v jsonb;
BEGIN
 SELECT * INTO a FROM laboratory_sample_associations WHERE id = NEW.association_id;
 SELECT * INTO r FROM laboratory_order_sample_requirements WHERE id = a.sample_requirement_id;
 SELECT * INTO s FROM laboratory_samples WHERE id = a.laboratory_sample_id;
 SELECT configuration INTO v FROM laboratory_exam_versions WHERE id = r.clinical_version_id;
 IF r.laboratory_order_exam_id IS DISTINCT FROM NEW.laboratory_order_exam_id OR a.released_at IS NOT NULL OR s.status IS DISTINCT FROM 'accepted'
  OR sample_compatibility_errors(r.configuration, to_jsonb(s), (v->>'schema_version')::integer, sample_operation_time()) <> '[]'::jsonb
 THEN RAISE EXCEPTION 'A processing dependency requires an adequate active accepted association on its own line' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
SQL);
        $this->updateReplacementAcceptance(false);
    }

    private function updateReplacementAcceptance(bool $includeReceived): void
    {
        $previous = "s.status='accepted'";
        $current = "s.status IN ('received', 'accepted')";
        foreach (['protect_clinical_replacement_record()', 'require_current_clinical_exception_evidence()'] as $function) {
            // Preserve every existing clinical guard, changing only the material
            // state predicate in these two installed replacement functions.
            $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$function])->definition;
            $from = $includeReceived ? $previous : $current;
            $to = $includeReceived ? $current : $previous;
            if (! str_contains($definition, $from) && ! str_contains($definition, $to)) {
                throw new LogicException('Replacement acceptance predicate was not found.');
            }
            DB::unprepared(str_replace($from, $to, $definition));
        }
    }
};
