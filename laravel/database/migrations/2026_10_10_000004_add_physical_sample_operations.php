<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_order_exams', function (Blueprint $table): void {
            $table->timestampTz('processing_started_at', 6)->nullable();
            $table->foreignId('processing_started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['laboratory_id', 'laboratory_order_id', 'id'], 'sample_line_identity');
        });
        Schema::table('laboratory_order_sample_requirements', fn (Blueprint $table) => $table->unique(['laboratory_id', 'laboratory_order_id', 'id'], 'sample_requirement_identity'));
        Schema::create('laboratory_samples', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_order_id');
            $table->string('identifier', 26)->unique();
            $table->unsignedBigInteger('sample_type_id');
            $table->string('provenance', 30);
            $table->string('status', 20);
            $table->timestampTz('collected_at', 6)->nullable();
            $table->unsignedBigInteger('collection_branch_id')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at', 6)->nullable();
            $table->unsignedBigInteger('receiving_branch_id')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('facts');
            $table->timestampsTz(6);
            $table->unique(['laboratory_id', 'laboratory_order_id', 'id'], 'physical_sample_identity');
            $table->foreign(['laboratory_id', 'laboratory_order_id'], 'physical_sample_order_fk')->references(['laboratory_id', 'id'])->on('laboratory_orders')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'sample_type_id'], 'physical_sample_type_fk')->references(['laboratory_id', 'id'])->on('sample_types')->restrictOnDelete();
            foreach (['collection_branch_id', 'receiving_branch_id'] as $branch) {
                $table->foreign(['laboratory_id', $branch], 'physical_'.$branch.'_fk')->references(['laboratory_id', 'id'])->on('branches')->restrictOnDelete();
            }
        });
        Schema::create('laboratory_sample_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_order_id');
            $table->unsignedBigInteger('laboratory_sample_id');
            $table->string('event', 30);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6);
            $table->jsonb('context');
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_sample_id'], 'sample_event_identity_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_samples')->restrictOnDelete();
            $table->index(['laboratory_sample_id', 'id']);
        });
        Schema::create('laboratory_sample_associations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_order_id');
            $table->unsignedBigInteger('laboratory_sample_id');
            $table->unsignedBigInteger('sample_requirement_id');
            $table->foreignId('associated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('associated_at', 6);
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at', 6)->nullable();
            $table->string('release_reason', 1000)->nullable();
            $table->unique(['laboratory_id', 'laboratory_order_id', 'id'], 'sample_association_identity');
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_sample_id'], 'sample_association_sample_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_samples')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'sample_requirement_id'], 'sample_association_requirement_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_order_sample_requirements')->restrictOnDelete();
            $table->index(['sample_requirement_id', 'released_at']);
        });
        Schema::create('laboratory_sample_processing_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_order_id');
            $table->unsignedBigInteger('laboratory_order_exam_id');
            $table->unsignedBigInteger('association_id');
            $table->jsonb('decision');
            $table->timestampTz('created_at', 6);
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id'], 'sample_dependency_line_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_order_exams')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'association_id'], 'sample_dependency_association_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_sample_associations')->restrictOnDelete();
            $table->unique(['laboratory_order_exam_id', 'association_id'], 'sample_dependency_unique');
        });
        Schema::create('laboratory_sample_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_order_id');
            $table->string('key', 100);
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('laboratory_sample_id')->nullable();
            $table->jsonb('response')->nullable();
            $table->unique(['laboratory_id', 'key'], 'sample_idempotency_unique');
            $table->foreign(['laboratory_id', 'laboratory_order_id'], 'sample_idempotency_order_fk')->references(['laboratory_id', 'id'])->on('laboratory_orders')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_sample_id'], 'sample_idempotency_sample_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_samples')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            $this->postgresConstraints();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP VIEW IF EXISTS laboratory_sample_requirement_satisfactions');
            DB::statement('DROP TRIGGER IF EXISTS protect_sample_processing ON laboratory_order_exams');
            DB::statement('ALTER TABLE laboratory_order_exams DROP CONSTRAINT IF EXISTS sample_processing_start_check');
            DB::statement('DROP FUNCTION IF EXISTS sample_compatibility_errors(jsonb, jsonb, integer, timestamptz) CASCADE');
        }
        foreach (['laboratory_sample_idempotencies', 'laboratory_sample_processing_dependencies', 'laboratory_sample_associations', 'laboratory_sample_events', 'laboratory_samples'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('laboratory_order_exams', function (Blueprint $table): void {
            $table->dropForeign(['processing_started_by']);
            $table->dropUnique('sample_line_identity');
            $table->dropColumn(['processing_started_at', 'processing_started_by']);
        });
        Schema::table('laboratory_order_sample_requirements', fn (Blueprint $table) => $table->dropUnique('sample_requirement_identity'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE laboratory_order_sample_requirements DROP CONSTRAINT order_requirement_values_check');
            DB::statement("ALTER TABLE laboratory_order_sample_requirements ADD CONSTRAINT order_requirement_values_check CHECK (position >= 0 AND status = 'pending' AND jsonb_typeof(configuration) = 'object')");
        }
    }

    private function postgresConstraints(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE laboratory_order_sample_requirements DROP CONSTRAINT order_requirement_values_check;
ALTER TABLE laboratory_order_sample_requirements ADD CONSTRAINT order_requirement_values_check
 CHECK (position >= 0 AND status IN ('pending', 'satisfied') AND jsonb_typeof(configuration) = 'object');
CREATE OR REPLACE FUNCTION sample_operation_time() RETURNS timestamptz LANGUAGE sql STABLE AS $$ SELECT statement_timestamp() $$;
CREATE OR REPLACE FUNCTION sample_compatibility_errors(r jsonb, s jsonb, schema_version integer, evaluated_at timestamptz)
 RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE errors jsonb := '[]'; field text; item record; collected timestamptz; received timestamptz;
BEGIN
 collected := (s->>'collected_at')::timestamptz; received := (s->>'received_at')::timestamptz;
 IF r->>'sample_type_id' IS DISTINCT FROM s->>'sample_type_id' THEN errors := errors || '"sample_type_mismatch"'::jsonb; END IF;
 IF schema_version = 1 AND coalesce(r->>'instructions', '') <> '' THEN errors := errors || '"unstructured_sample_instructions"'::jsonb; END IF;
 IF r->>'container' IS NOT NULL AND r->'container' IS DISTINCT FROM s->'facts'->'container' THEN errors := errors || '"container_mismatch_or_unknown"'::jsonb; END IF;
 IF r->>'volume' IS NOT NULL THEN
  IF r->'volume_unit' IS DISTINCT FROM s->'facts'->'volume_unit' OR s->'facts'->>'volume' IS NULL THEN errors := errors || '"volume_unknown_or_unit_mismatch"'::jsonb;
  ELSIF (s->'facts'->>'volume')::numeric < (r->>'volume')::numeric THEN errors := errors || '"insufficient_volume"'::jsonb; END IF;
 END IF;
 IF r ? 'additives' AND NOT (coalesce(s->'facts'->'additives', 'null'::jsonb) @> (r->'additives') AND (r->'additives') @> coalesce(s->'facts'->'additives', 'null'::jsonb)) THEN errors := errors || '"additives_mismatch_or_unknown"'::jsonb; END IF;
 FOREACH field IN ARRAY ARRAY['collection_conditions', 'storage_conditions'] LOOP
  FOR item IN SELECT key, value FROM jsonb_each(coalesce(r->field, '{}'::jsonb)) LOOP
   IF item.value IS DISTINCT FROM s->'facts'->field->item.key THEN errors := errors || to_jsonb(field || '_mismatch_or_unknown:' || item.key); END IF;
  END LOOP;
 END LOOP;
 IF r ? 'max_age_minutes' THEN
  IF collected IS NULL THEN errors := errors || '"collection_time_unknown"'::jsonb;
  ELSIF evaluated_at < collected OR evaluated_at > collected + (r->>'max_age_minutes')::integer * interval '1 minute' THEN errors := errors || '"sample_age_exceeded"'::jsonb; END IF;
 END IF;
 IF r ? 'max_transport_minutes' THEN
  IF collected IS NULL OR received IS NULL THEN errors := errors || '"transport_time_unknown"'::jsonb;
  ELSIF received < collected OR received > collected + (r->>'max_transport_minutes')::integer * interval '1 minute' THEN errors := errors || '"transport_time_exceeded"'::jsonb; END IF;
 END IF;
 RETURN errors;
END $$;
CREATE OR REPLACE VIEW laboratory_sample_requirement_satisfactions AS
 SELECT r.id AS sample_requirement_id, r.laboratory_id, r.laboratory_order_id,
 CASE WHEN EXISTS (
  SELECT 1 FROM laboratory_sample_associations a JOIN laboratory_samples s ON s.id = a.laboratory_sample_id
  JOIN laboratory_exam_versions v ON v.id = r.clinical_version_id
  WHERE a.sample_requirement_id = r.id AND a.released_at IS NULL AND s.status = 'accepted'
   AND sample_compatibility_errors(r.configuration, to_jsonb(s), (v.configuration->>'schema_version')::integer, sample_operation_time()) = '[]'::jsonb
 ) THEN 'satisfied' ELSE 'pending' END AS status FROM laboratory_order_sample_requirements r;
CREATE UNIQUE INDEX sample_active_association_unique ON laboratory_sample_associations(laboratory_sample_id, sample_requirement_id) WHERE released_at IS NULL;
ALTER TABLE laboratory_samples ADD CONSTRAINT physical_sample_values_check CHECK (
 identifier ~ '^[0-9A-HJKMNP-TV-Z]{26}$' AND provenance IN ('internal', 'patient_provided', 'external_center')
 AND status IN ('collected', 'received', 'accepted', 'rejected') AND jsonb_typeof(facts) = 'object'
 AND (status <> 'collected' OR (provenance = 'internal' AND collected_at IS NOT NULL AND collected_by IS NOT NULL AND collection_branch_id IS NOT NULL))
 AND (provenance = 'internal' OR (collected_by IS NULL AND collection_branch_id IS NULL))
 AND ((received_at IS NULL AND received_by IS NULL AND receiving_branch_id IS NULL AND status IN ('collected', 'rejected'))
  OR (received_at IS NOT NULL AND received_by IS NOT NULL AND receiving_branch_id IS NOT NULL AND status <> 'collected'))
 AND (collected_at IS NULL OR received_at IS NULL OR received_at >= collected_at));
ALTER TABLE laboratory_order_exams ADD CONSTRAINT sample_processing_start_check CHECK (
 (processing_started_at IS NULL AND processing_started_by IS NULL) OR
 (processing_started_at IS NOT NULL AND processing_started_by IS NOT NULL AND clinical_version_id IS NOT NULL AND clinical_activity_started_at IS NOT NULL));
ALTER TABLE laboratory_sample_associations ADD CONSTRAINT sample_release_values_check CHECK (
 (released_at IS NULL AND released_by IS NULL AND release_reason IS NULL) OR
 (released_at IS NOT NULL AND released_by IS NOT NULL AND length(trim(release_reason)) > 0 AND released_at >= associated_at));
CREATE OR REPLACE FUNCTION protect_physical_sample() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status NOT IN ('collected', 'received') THEN RAISE EXCEPTION 'A physical sample begins only on actual collection or reception' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Physical sample history cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF (NEW.id, NEW.laboratory_id, NEW.laboratory_order_id, NEW.identifier, NEW.sample_type_id, NEW.provenance, NEW.collected_at, NEW.collection_branch_id, NEW.collected_by)
  IS DISTINCT FROM (OLD.id, OLD.laboratory_id, OLD.laboratory_order_id, OLD.identifier, OLD.sample_type_id, OLD.provenance, OLD.collected_at, OLD.collection_branch_id, OLD.collected_by)
 THEN RAISE EXCEPTION 'Physical sample identity and observed facts are immutable' USING ERRCODE = '23514'; END IF;
 IF NEW.facts IS DISTINCT FROM OLD.facts AND NOT (OLD.status = 'collected' AND NEW.status = 'received' AND
  NOT EXISTS (SELECT 1 FROM jsonb_each(OLD.facts) f WHERE NEW.facts->f.key IS DISTINCT FROM f.value))
 THEN RAISE EXCEPTION 'Observed facts may only be completed at first reception, without changing previous observations' USING ERRCODE = '23514'; END IF;
 IF OLD.received_at IS NOT NULL AND (NEW.received_at, NEW.receiving_branch_id, NEW.received_by) IS DISTINCT FROM (OLD.received_at, OLD.receiving_branch_id, OLD.received_by)
 THEN RAISE EXCEPTION 'Reception is immutable' USING ERRCODE = '23514'; END IF;
 IF NEW.status IS DISTINCT FROM OLD.status AND NOT ((OLD.status = 'collected' AND NEW.status IN ('received', 'rejected')) OR (OLD.status = 'received' AND NEW.status IN ('accepted', 'rejected')) OR (OLD.status = 'accepted' AND NEW.status = 'rejected'))
 THEN RAISE EXCEPTION 'Invalid physical sample transition' USING ERRCODE = '23514'; END IF;
 IF NEW.status = 'rejected' AND OLD.status <> 'rejected' AND EXISTS (
  SELECT 1 FROM laboratory_sample_processing_dependencies d JOIN laboratory_sample_associations a ON a.id = d.association_id WHERE a.laboratory_sample_id = OLD.id
 ) THEN RAISE EXCEPTION 'A processing dependency prevents sample rejection' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_physical_sample BEFORE INSERT OR UPDATE OR DELETE ON laboratory_samples FOR EACH ROW EXECUTE FUNCTION protect_physical_sample();
CREATE OR REPLACE FUNCTION protect_sample_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Sample history is append-only' USING ERRCODE = '23514'; END $$;
CREATE TRIGGER protect_sample_satisfaction_view INSTEAD OF INSERT OR UPDATE OR DELETE ON laboratory_sample_requirement_satisfactions FOR EACH ROW EXECUTE FUNCTION protect_sample_history();
CREATE TRIGGER protect_sample_events BEFORE UPDATE OR DELETE ON laboratory_sample_events FOR EACH ROW EXECUTE FUNCTION protect_sample_history();
CREATE TRIGGER protect_sample_dependencies BEFORE UPDATE OR DELETE ON laboratory_sample_processing_dependencies FOR EACH ROW EXECUTE FUNCTION protect_sample_history();
CREATE OR REPLACE FUNCTION protect_sample_association() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r laboratory_order_sample_requirements; s laboratory_samples; v jsonb;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Association evidence cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  SELECT * INTO r FROM laboratory_order_sample_requirements WHERE id = NEW.sample_requirement_id;
  SELECT * INTO s FROM laboratory_samples WHERE id = NEW.laboratory_sample_id;
  SELECT configuration INTO v FROM laboratory_exam_versions WHERE id = r.clinical_version_id;
  IF s.status = 'rejected' OR sample_compatibility_errors(r.configuration, to_jsonb(s), (v->>'schema_version')::integer, sample_operation_time()) <> '[]'::jsonb
   OR EXISTS (SELECT 1 FROM laboratory_order_exams WHERE id = r.laboratory_order_exam_id AND processing_started_at IS NOT NULL)
  THEN RAISE EXCEPTION 'Association is not clinically compatible or line already started' USING ERRCODE = '23514'; END IF;
 ELSE
  IF (NEW.id, NEW.laboratory_id, NEW.laboratory_order_id, NEW.laboratory_sample_id, NEW.sample_requirement_id, NEW.associated_by, NEW.associated_at)
   IS DISTINCT FROM (OLD.id, OLD.laboratory_id, OLD.laboratory_order_id, OLD.laboratory_sample_id, OLD.sample_requirement_id, OLD.associated_by, OLD.associated_at)
   OR OLD.released_at IS NOT NULL THEN RAISE EXCEPTION 'Association identity and release history are immutable' USING ERRCODE = '23514'; END IF;
  IF EXISTS (SELECT 1 FROM laboratory_sample_processing_dependencies WHERE association_id = OLD.id) THEN RAISE EXCEPTION 'Cannot release a processing dependency' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_sample_association BEFORE INSERT OR UPDATE OR DELETE ON laboratory_sample_associations FOR EACH ROW EXECUTE FUNCTION protect_sample_association();
CREATE OR REPLACE FUNCTION mark_sample_association_activity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE laboratory_order_sample_requirements SET clinical_activity_started_at = coalesce(clinical_activity_started_at, NEW.associated_at) WHERE id = NEW.sample_requirement_id;
 UPDATE laboratory_order_exams SET clinical_activity_started_at = coalesce(clinical_activity_started_at, NEW.associated_at)
  WHERE id = (SELECT laboratory_order_exam_id FROM laboratory_order_sample_requirements WHERE id = NEW.sample_requirement_id);
 RETURN NEW;
END $$;
CREATE TRIGGER mark_sample_association_activity AFTER INSERT ON laboratory_sample_associations FOR EACH ROW EXECUTE FUNCTION mark_sample_association_activity();
CREATE OR REPLACE FUNCTION protect_sample_idempotency() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Sample idempotency cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF OLD.response IS NOT NULL OR (NEW.laboratory_id, NEW.laboratory_order_id, NEW.key, NEW.payload_hash)
  IS DISTINCT FROM (OLD.laboratory_id, OLD.laboratory_order_id, OLD.key, OLD.payload_hash)
 THEN RAISE EXCEPTION 'Completed sample idempotency is immutable' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_sample_idempotency BEFORE UPDATE OR DELETE ON laboratory_sample_idempotencies FOR EACH ROW EXECUTE FUNCTION protect_sample_idempotency();
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
CREATE TRIGGER protect_sample_dependency BEFORE INSERT ON laboratory_sample_processing_dependencies FOR EACH ROW EXECUTE FUNCTION protect_sample_dependency();
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
CREATE TRIGGER protect_sample_processing BEFORE INSERT OR UPDATE ON laboratory_order_exams FOR EACH ROW EXECUTE FUNCTION protect_sample_processing();
SQL);
    }
};
