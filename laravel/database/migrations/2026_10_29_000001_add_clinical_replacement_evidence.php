<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['clinical_replacement_intents', 'clinical_line_evidence', 'clinical_replacement_links', 'clinical_replacement_events', 'clinical_viability_assessments'];

    public function up(): void
    {
        Schema::create('clinical_replacement_contexts', function (Blueprint $t): void {
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('laboratory_order_exam_id')->primary();
            $t->unsignedBigInteger('revision')->default(0);
            $t->unique(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'], 'replacement_context_identity');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'], 'replacement_context_line_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_order_exams')->restrictOnDelete();
        });
        foreach (self::TABLES as $index => $table) {
            Schema::create($table, function (Blueprint $t) use ($index, $table): void {
                $t->id();
                foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'] as $field) {
                    $t->unsignedBigInteger($field);
                }
                $t->unsignedBigInteger('sample_requirement_id')->nullable();
                $t->unsignedBigInteger('sample_id')->nullable();
                $t->unsignedBigInteger('recorded_by');
                $t->timestampTz('occurred_at', 6);
                $t->timestampTz('recorded_at', 6)->useCurrent();
                $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'], "replacement_{$index}_context_fk")->references(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'])->on('clinical_replacement_contexts')->restrictOnDelete();
                $t->foreign(['laboratory_id', 'laboratory_order_id', 'sample_requirement_id'], "replacement_{$index}_requirement_fk")->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_order_sample_requirements')->restrictOnDelete();
                $t->foreign(['laboratory_id', 'laboratory_order_id', 'sample_id'], "replacement_{$index}_sample_fk")->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_samples')->restrictOnDelete();
                $t->foreign(['laboratory_id', 'recorded_by'], "replacement_{$index}_actor_fk")->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
                $t->unique(['laboratory_id', 'laboratory_order_exam_id', 'id'], "replacement_{$index}_identity");
                $t->index(['laboratory_order_exam_id', 'id']);
                if ($table === 'clinical_replacement_intents') {
                    $t->text('reason');
                    $t->text('justification');
                } elseif ($table === 'clinical_line_evidence') {
                    $t->string('kind', 40);
                    $t->jsonb('facts');
                } else {
                    $t->unsignedBigInteger('intention_id')->nullable();
                    $t->foreign(['laboratory_id', 'laboratory_order_exam_id', 'intention_id'], "replacement_{$index}_intention_fk")->references(['laboratory_id', 'laboratory_order_exam_id', 'id'])->on('clinical_replacement_intents')->restrictOnDelete();
                    if ($table === 'clinical_replacement_links') {
                        $t->unsignedBigInteger('association_id');
                        $t->foreign(['laboratory_id', 'laboratory_order_id', 'association_id'], 'replacement_link_association_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_sample_associations')->restrictOnDelete();
                        $t->unique(['intention_id', 'sample_id'], 'replacement_link_unique');
                    } elseif ($table === 'clinical_replacement_events') {
                        $t->string('event', 30);
                        $t->unsignedBigInteger('link_id')->nullable();
                        $t->text('justification');
                        $t->foreign(['laboratory_id', 'laboratory_order_exam_id', 'link_id'], 'replacement_event_link_fk')->references(['laboratory_id', 'laboratory_order_exam_id', 'id'])->on('clinical_replacement_links')->restrictOnDelete();
                    } else {
                        $t->string('subject', 30);
                        $t->unsignedBigInteger('revision');
                        $t->unsignedBigInteger('previous_id')->nullable();
                        $t->string('outcome', 20);
                        $t->text('justification');
                        $t->jsonb('evidence_ids');
                        $t->jsonb('dependency_snapshot');
                        $t->foreign(['laboratory_id', 'laboratory_order_exam_id', 'previous_id'], 'viability_previous_fk')->references(['laboratory_id', 'laboratory_order_exam_id', 'id'])->on('clinical_viability_assessments')->restrictOnDelete();
                    }
                }
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            $this->postgres();
        }
    }

    private function postgres(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_replacement_dependencies(line_id bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object(
  'intents', coalesce((SELECT jsonb_agg(to_jsonb(i) ORDER BY i.id) FROM clinical_replacement_intents i WHERE i.laboratory_order_exam_id=line_id), '[]'),
  'evidence', coalesce((SELECT jsonb_agg(to_jsonb(e) ORDER BY e.id) FROM clinical_line_evidence e WHERE e.laboratory_order_exam_id=line_id), '[]'),
  'links', coalesce((SELECT jsonb_agg(to_jsonb(l) ORDER BY l.id) FROM clinical_replacement_links l WHERE l.laboratory_order_exam_id=line_id), '[]'),
  'events', coalesce((SELECT jsonb_agg(to_jsonb(e) ORDER BY e.id) FROM clinical_replacement_events e WHERE e.laboratory_order_exam_id=line_id), '[]'),
  'requirements', coalesce((SELECT jsonb_agg(to_jsonb(r) ORDER BY r.id) FROM laboratory_order_sample_requirements r WHERE r.laboratory_order_exam_id=line_id), '[]'),
  'samples', coalesce((SELECT jsonb_agg(to_jsonb(s) ORDER BY s.id) FROM laboratory_samples s WHERE s.id IN (
    SELECT sample_id FROM clinical_replacement_intents WHERE laboratory_order_exam_id=line_id
    UNION SELECT sample_id FROM clinical_replacement_links WHERE laboratory_order_exam_id=line_id
    UNION SELECT a.laboratory_sample_id FROM laboratory_sample_associations a JOIN laboratory_order_sample_requirements r ON r.id=a.sample_requirement_id WHERE r.laboratory_order_exam_id=line_id)), '[]'),
  'associations', coalesce((SELECT jsonb_agg(to_jsonb(a) ORDER BY a.id) FROM laboratory_sample_associations a JOIN laboratory_order_sample_requirements r ON r.id=a.sample_requirement_id WHERE r.laboratory_order_exam_id=line_id), '[]')
 )
$$;
CREATE OR REPLACE FUNCTION protect_clinical_replacement_context() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR (TG_OP='UPDATE' AND (pg_trigger_depth()<2 OR NEW IS DISTINCT FROM jsonb_populate_record(OLD,jsonb_build_object('revision',OLD.revision+1))))
 THEN RAISE EXCEPTION 'Clinical replacement context is a protected revision projection' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' AND NEW.revision<>0 THEN RAISE EXCEPTION 'Replacement context starts at revision zero' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_replacement_context BEFORE INSERT OR UPDATE OR DELETE ON clinical_replacement_contexts FOR EACH ROW EXECUTE FUNCTION protect_clinical_replacement_context();
CREATE OR REPLACE FUNCTION advance_clinical_replacement_context() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE clinical_replacement_contexts SET revision=revision+1 WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION protect_clinical_replacement_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE intention clinical_replacement_intents; prior clinical_viability_assessments; link clinical_replacement_links; required_id bigint; required_kind text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Clinical replacement evidence is immutable' USING ERRCODE='23514'; END IF;
 PERFORM 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;
 PERFORM 1 FROM laboratory_order_exams WHERE id=NEW.laboratory_order_exam_id FOR UPDATE;
 PERFORM 1 FROM clinical_replacement_contexts WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM laboratory_orders WHERE id=NEW.laboratory_order_id AND status IN ('pending','in_process'))
 OR EXISTS(SELECT 1 FROM clinical_line_resolutions WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id)
 THEN RAISE EXCEPTION 'Replacement evidence requires an open unresolved clinical line' USING ERRCODE='23514'; END IF;
 IF NEW.sample_requirement_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM laboratory_order_sample_requirements WHERE id=NEW.sample_requirement_id AND laboratory_order_exam_id=NEW.laboratory_order_exam_id)
 THEN RAISE EXCEPTION 'Replacement requirement must belong to the clinical line' USING ERRCODE='23514'; END IF;
 IF NEW.occurred_at>NEW.recorded_at THEN RAISE EXCEPTION 'Clinical facts cannot occur after their record' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='clinical_replacement_intents' THEN
  IF NEW.sample_requirement_id IS NULL OR length(btrim(NEW.reason))=0 OR length(btrim(NEW.justification))=0 THEN RAISE EXCEPTION 'Replacement intention requires requirement and justification' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='clinical_line_evidence' THEN
  IF NOT clinical_line_evidence_valid(NEW.kind,NEW.facts) THEN RAISE EXCEPTION 'Invalid structured clinical evidence' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.intention_id IS NOT NULL THEN
   SELECT * INTO intention FROM clinical_replacement_intents WHERE id=NEW.intention_id;
   IF NEW.sample_requirement_id IS DISTINCT FROM intention.sample_requirement_id THEN RAISE EXCEPTION 'Replacement requirement must match intention' USING ERRCODE='23514'; END IF;
   IF TG_TABLE_NAME<>'clinical_replacement_links' AND NEW.sample_id IS DISTINCT FROM intention.sample_id THEN RAISE EXCEPTION 'Evidence must retain the original affected sample' USING ERRCODE='23514'; END IF;
  END IF;
  IF TG_TABLE_NAME='clinical_replacement_links' THEN
   IF NEW.intention_id IS NULL OR NEW.sample_id IS NULL OR NEW.sample_id=intention.sample_id OR NOT EXISTS(
    SELECT 1 FROM laboratory_sample_associations a JOIN laboratory_samples s ON s.id=a.laboratory_sample_id JOIN laboratory_order_sample_requirements r ON r.id=a.sample_requirement_id JOIN laboratory_exam_versions v ON v.id=r.clinical_version_id
    WHERE a.id=NEW.association_id AND a.laboratory_sample_id=NEW.sample_id AND a.sample_requirement_id=NEW.sample_requirement_id AND a.released_at IS NULL AND s.status<>'rejected' AND sample_compatibility_errors(r.configuration,to_jsonb(s),(v.configuration->>'schema_version')::integer,sample_operation_time())='[]'::jsonb)
   THEN RAISE EXCEPTION 'Replacement link requires a distinct associated sample' USING ERRCODE='23514'; END IF;
   IF EXISTS(WITH RECURSIVE chain(id) AS (
    SELECT NEW.sample_id UNION SELECT l.sample_id FROM clinical_replacement_links l JOIN clinical_replacement_intents i ON i.id=l.intention_id JOIN chain c ON i.sample_id=c.id WHERE l.laboratory_order_id=NEW.laboratory_order_id
   ) SELECT 1 FROM chain WHERE id=intention.sample_id)
   THEN RAISE EXCEPTION 'Replacement sample chain cannot contain a cycle' USING ERRCODE='23514'; END IF;
  ELSIF TG_TABLE_NAME='clinical_replacement_events' THEN
   IF NEW.intention_id IS NULL OR NEW.event NOT IN ('opened','material_linked','fulfilled') OR length(btrim(NEW.justification))=0 THEN RAISE EXCEPTION 'Invalid replacement event' USING ERRCODE='23514'; END IF;
   IF NEW.event IN ('material_linked','fulfilled') THEN
    SELECT * INTO link FROM clinical_replacement_links WHERE id=NEW.link_id;
    IF link.id IS NULL OR link.intention_id<>NEW.intention_id THEN RAISE EXCEPTION 'Replacement event requires its own material link' USING ERRCODE='23514'; END IF;
    IF NEW.event='fulfilled' AND NOT EXISTS(SELECT 1 FROM laboratory_sample_associations a JOIN laboratory_samples s ON s.id=a.laboratory_sample_id JOIN laboratory_order_sample_requirements r ON r.id=a.sample_requirement_id JOIN laboratory_exam_versions v ON v.id=r.clinical_version_id
     WHERE a.id=link.association_id AND a.released_at IS NULL AND s.status='accepted' AND sample_compatibility_errors(r.configuration,to_jsonb(s),(v.configuration->>'schema_version')::integer,sample_operation_time())='[]'::jsonb)
    THEN RAISE EXCEPTION 'Fulfillment requires an adequate accepted sample' USING ERRCODE='23514'; END IF;
   ELSIF NEW.link_id IS NOT NULL THEN RAISE EXCEPTION 'Opening does not link material' USING ERRCODE='23514'; END IF;
  ELSE
   IF NEW.subject NOT IN ('replacement','continuation','technical_alternatives') OR (NEW.subject='replacement')<>(NEW.intention_id IS NOT NULL) OR NEW.outcome NOT IN ('viable','non_viable') OR length(btrim(NEW.justification))=0
   THEN RAISE EXCEPTION 'Invalid viability assessment subject or outcome' USING ERRCODE='23514'; END IF;
   SELECT * INTO prior FROM clinical_viability_assessments WHERE laboratory_order_exam_id=NEW.laboratory_order_exam_id AND subject=NEW.subject AND intention_id IS NOT DISTINCT FROM NEW.intention_id ORDER BY revision DESC LIMIT 1;
   IF NEW.previous_id IS DISTINCT FROM prior.id OR NEW.revision<>coalesce(prior.revision,0)+1 THEN RAISE EXCEPTION 'Viability assessment predecessor is obsolete' USING ERRCODE='23514'; END IF;
   IF jsonb_typeof(NEW.evidence_ids)<>'array' OR jsonb_array_length(NEW.evidence_ids)=0 THEN RAISE EXCEPTION 'Assessment requires structured evidence references' USING ERRCODE='23514'; END IF;
   FOR required_id IN SELECT value::text::bigint FROM jsonb_array_elements(NEW.evidence_ids) LOOP
    IF NOT EXISTS(SELECT 1 FROM clinical_line_evidence e WHERE e.id=required_id AND e.laboratory_order_exam_id=NEW.laboratory_order_exam_id AND e.laboratory_id=NEW.laboratory_id AND (NEW.intention_id IS NULL OR ((e.sample_id IS NULL OR e.sample_id IS NOT DISTINCT FROM intention.sample_id) AND (e.sample_requirement_id IS NULL OR e.sample_requirement_id=intention.sample_requirement_id)))) THEN RAISE EXCEPTION 'Assessment evidence must belong to its clinical line' USING ERRCODE='23514'; END IF;
   END LOOP;
   FOREACH required_kind IN ARRAY CASE NEW.subject WHEN 'replacement' THEN ARRAY['sample_problem'] WHEN 'continuation' THEN ARRAY['follow_up'] ELSE ARRAY['technical_problem','alternatives'] END LOOP
    IF NOT EXISTS(SELECT 1 FROM clinical_line_evidence e WHERE e.kind=required_kind AND NEW.evidence_ids @> jsonb_build_array(e.id) AND e.laboratory_order_exam_id=NEW.laboratory_order_exam_id) THEN RAISE EXCEPTION 'Assessment requires applicable structured evidence kinds' USING ERRCODE='23514'; END IF;
   END LOOP;
   IF NEW.dependency_snapshot IS DISTINCT FROM clinical_replacement_dependencies(NEW.laboratory_order_exam_id) THEN RAISE EXCEPTION 'Viability dependencies are obsolete' USING ERRCODE='23514'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION clinical_line_evidence_valid(kind text, facts jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE fields text[]; field text;
BEGIN
 IF jsonb_typeof(facts) IS DISTINCT FROM 'object' THEN RETURN false; END IF;
 fields := CASE kind WHEN 'sample_problem' THEN ARRAY['problem'] WHEN 'patient_refusal' THEN ARRAY['express_refusal','observations'] WHEN 'follow_up' THEN ARRAY['action','observations'] WHEN 'medical_instruction' THEN ARRAY['instruction'] WHEN 'technical_problem' THEN ARRAY['problem'] WHEN 'alternatives' THEN ARRAY['alternatives_considered'] ELSE NULL END;
 IF fields IS NULL OR (SELECT count(*) FROM jsonb_object_keys(facts))<>cardinality(fields) THEN RETURN false; END IF;
 FOREACH field IN ARRAY fields LOOP
  IF field='express_refusal' THEN IF facts->field IS DISTINCT FROM 'true'::jsonb THEN RETURN false; END IF;
  ELSIF jsonb_typeof(facts->field) IS DISTINCT FROM 'string' OR length(btrim(facts->>field))=0 OR length(facts->>field)>4000 THEN RETURN false;
  END IF;
 END LOOP;
 RETURN true;
END $$;
CREATE UNIQUE INDEX viability_subject_revision ON clinical_viability_assessments(laboratory_order_exam_id,subject,coalesce(intention_id,0),revision);
CREATE UNIQUE INDEX viability_single_successor ON clinical_viability_assessments(previous_id) WHERE previous_id IS NOT NULL;
SQL);
        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER protect_replacement_record BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION protect_clinical_replacement_record()");
            DB::statement("CREATE TRIGGER advance_replacement_revision AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION advance_clinical_replacement_context()");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Clinical replacement history exists; explicit restoration is required.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION clinical_replacement_dependencies(bigint)');
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
        Schema::drop('clinical_replacement_contexts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION protect_clinical_replacement_record(); DROP FUNCTION advance_clinical_replacement_context(); DROP FUNCTION protect_clinical_replacement_context(); DROP FUNCTION clinical_line_evidence_valid(text,jsonb);');
        }
    }
};
