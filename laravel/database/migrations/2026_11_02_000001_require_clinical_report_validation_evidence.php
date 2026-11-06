<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_report_classifications(results jsonb, refs jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE r jsonb; ref jsonb; rule jsonb; classification text; output jsonb := '[]'::jsonb; n numeric;
BEGIN
 FOR r IN SELECT value FROM jsonb_array_elements(results) LOOP
  SELECT value INTO ref FROM jsonb_array_elements(refs) WHERE value->>'parameter_code'=r->>'parameter_code';
  rule := CASE WHEN ref->>'status'='resolved' THEN ref->'reference' ELSE NULL END;
  classification := 'not_classified';
  IF rule IS NOT NULL AND r->>'value_type'='numeric' THEN
   n := (r->>'value')::numeric;
   IF (rule->>'critical_min' IS NOT NULL AND n <= (rule->>'critical_min')::numeric) OR (rule->>'critical_max' IS NOT NULL AND n >= (rule->>'critical_max')::numeric) THEN classification := 'critical';
   ELSIF rule->>'min' IS NOT NULL AND n < (rule->>'min')::numeric THEN classification := 'low';
   ELSIF rule->>'max' IS NOT NULL AND n > (rule->>'max')::numeric THEN classification := 'high';
   ELSIF rule->>'min' IS NOT NULL OR rule->>'max' IS NOT NULL THEN classification := 'normal'; END IF;
  ELSIF rule ? 'allowed_options' AND r->>'value_type' IN ('qualitative','predefined_options') THEN
   classification := CASE WHEN rule->'allowed_options' @> jsonb_build_array(r->>'value') THEN 'normal' ELSE 'outside_reference' END;
  END IF;
  output := output || jsonb_build_array(jsonb_build_object('parameter_code',r->>'parameter_code','status',classification));
 END LOOP;
 RETURN output;
END $$;
CREATE OR REPLACE FUNCTION protect_clinical_report_validation_details() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ctx jsonb := NEW.context_snapshot; expected jsonb; critical jsonb; observation jsonb;
BEGIN
 expected := clinical_report_classifications(NEW.results_snapshot,NEW.reference_snapshot);
 IF ctx->>'report_evidence_version' IS DISTINCT FROM '1' OR ctx->'classifications' IS DISTINCT FROM expected
 OR ctx->'validator_identity' IS DISTINCT FROM (SELECT jsonb_build_object('id',id,'name',name) FROM users WHERE id=NEW.validated_by)
 OR jsonb_typeof(ctx->'observations') IS DISTINCT FROM 'array' THEN
  RAISE EXCEPTION 'Clinical report evidence must match current validated clinical data and validator identity' USING ERRCODE='23514';
 END IF;
 SELECT coalesce(jsonb_agg(value->>'parameter_code'),'[]'::jsonb) INTO critical FROM jsonb_array_elements(expected) WHERE value->>'status'='critical';
 IF jsonb_array_length(critical)>0 AND ((ctx->'critical_confirmation'->>'confirmed_by')::bigint IS DISTINCT FROM NEW.validated_by
 OR ctx->'critical_confirmation'->'parameter_codes' IS DISTINCT FROM critical OR ctx->'critical_confirmation'->>'confirmed_at' IS NULL) THEN
  RAISE EXCEPTION 'Critical values require the authorized validator explicit confirmation' USING ERRCODE='23514';
 END IF;
 FOR observation IN SELECT value FROM jsonb_array_elements(ctx->'observations') LOOP
  IF observation->>'scope' IS NULL OR observation->>'scope' NOT IN ('parameter','exam','area','report')
   OR trim(coalesce(observation->>'text',''))='' OR (observation->>'author_id')::bigint IS DISTINCT FROM NEW.validated_by
   OR observation->>'author_name' IS DISTINCT FROM ctx->'validator_identity'->>'name'
   OR (observation->>'line_id')::bigint IS DISTINCT FROM NEW.laboratory_order_exam_id OR observation->>'authorized_at' IS NULL THEN
   RAISE EXCEPTION 'Report observations require explicit clinical authorization and traceability' USING ERRCODE='23514';
  END IF;
  IF observation->>'scope'='parameter' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.results_snapshot) r WHERE r->>'parameter_code'=observation->>'parameter_code') THEN
   RAISE EXCEPTION 'Observation parameter is outside this validation' USING ERRCODE='23514';
  END IF;
  IF observation->>'scope'='area' AND (observation->'area' IS DISTINCT FROM (SELECT report_area_snapshot FROM laboratory_order_exams WHERE id=NEW.laboratory_order_exam_id) OR observation->'area'='null'::jsonb) THEN
   RAISE EXCEPTION 'Observation area must match historical exam area' USING ERRCODE='23514';
  END IF;
 END LOOP;
 IF ctx->'signature_snapshot' IS NOT NULL AND ctx->'signature_snapshot'<>'null'::jsonb THEN
  IF NOT EXISTS(SELECT 1 FROM validation_signature_events e JOIN validation_professionals p ON p.id=e.professional_id
   WHERE e.id=(ctx->'signature_snapshot'->>'grant_id')::bigint AND e.laboratory_id=NEW.laboratory_id AND e.user_id=NEW.validated_by AND e.action='grant'
   AND p.status='authorized' AND p.user_id=NEW.validated_by AND p.laboratory_id=NEW.laboratory_id
   AND e.id=(SELECT max(id) FROM validation_signature_events WHERE laboratory_id=NEW.laboratory_id AND user_id=NEW.validated_by)
   AND e.image=ctx->'signature_snapshot'->>'image' AND e.image_hash=ctx->'signature_snapshot'->>'image_hash'
   AND ctx->'signature_snapshot'->'professional'=jsonb_build_object('id',p.id,'user_id',p.user_id,'professional_name',p.professional_name,'profession',p.profession,'registration_number',p.registration_number))
   OR (ctx->'signature_snapshot'->>'confirmed_by')::bigint IS DISTINCT FROM NEW.validated_by OR ctx->'signature_snapshot'->>'confirmed_at' IS NULL THEN
   RAISE EXCEPTION 'Digital signature requires its owner current authorization and explicit validation consent' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER zz_protect_clinical_report_validation_details BEFORE INSERT ON laboratory_result_validations FOR EACH ROW EXECUTE FUNCTION protect_clinical_report_validation_details();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS zz_protect_clinical_report_validation_details ON laboratory_result_validations; DROP FUNCTION IF EXISTS protect_clinical_report_validation_details(); DROP FUNCTION IF EXISTS clinical_report_classifications(jsonb,jsonb);');
    }
};
