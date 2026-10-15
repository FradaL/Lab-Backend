<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_order_result_captures', function (Blueprint $table): void {
            $table->id();
            foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->unsignedBigInteger('revision');
            $table->string('status', 20)->default('preliminary');
            $table->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('captured_at', 6);
            $table->unique('laboratory_order_exam_id', 'result_capture_line_unique');
            $identity = ['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id'];
            $table->unique($identity, 'result_capture_identity');
            $table->foreign($identity, 'result_capture_line_fk')->references(['laboratory_id', 'laboratory_order_id', 'id', 'laboratory_exam_id', 'clinical_version_id'])->on('laboratory_order_exams')->restrictOnDelete();
        });
        Schema::create('laboratory_order_results', function (Blueprint $table): void {
            $table->id();
            foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->string('parameter_code', 60);
            $table->string('value_type', 30);
            $table->jsonb('parameter_snapshot');
            $table->text('numeric_value')->nullable();
            $table->text('text_value')->nullable();
            $table->string('option_code', 60)->nullable();
            $table->string('option_label', 150)->nullable();
            $table->string('unit', 50)->nullable();
            $table->unsignedBigInteger('capture_revision');
            $table->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('captured_at', 6);
            $table->unique(['laboratory_order_exam_id', 'parameter_code'], 'result_parameter_unique');
            $identity = ['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id'];
            $table->foreign($identity, 'result_parameter_capture_fk')->references($identity)->on('laboratory_order_result_captures')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE laboratory_order_results ALTER COLUMN numeric_value TYPE numeric USING numeric_value::numeric;
ALTER TABLE laboratory_order_result_captures ADD CONSTRAINT result_capture_values_check CHECK (revision >= 1 AND status = 'preliminary');
ALTER TABLE laboratory_order_results ADD CONSTRAINT result_values_check CHECK (
 capture_revision >= 1 AND jsonb_typeof(parameter_snapshot) = 'object' AND (
 (value_type = 'numeric' AND numeric_value IS NOT NULL AND numeric_value::text NOT IN ('NaN','Infinity','-Infinity') AND text_value IS NULL AND option_code IS NULL AND option_label IS NULL) OR
 (value_type = 'text' AND text_value IS NOT NULL AND text_value !~ '^[[:space:]]*$' AND numeric_value IS NULL AND option_code IS NULL AND option_label IS NULL AND unit IS NULL) OR
 (value_type IN ('qualitative','predefined_options') AND option_code IS NOT NULL AND option_label IS NOT NULL AND numeric_value IS NULL AND text_value IS NULL AND unit IS NULL)));
CREATE OR REPLACE FUNCTION protect_result_capture() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Capture identity and revision history cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF NOT EXISTS (SELECT 1 FROM laboratory_order_exams l JOIN laboratory_orders o ON o.id = l.laboratory_order_id
  JOIN laboratory_exam_versions v ON v.id = l.clinical_version_id
  WHERE l.id = NEW.laboratory_order_exam_id AND l.processing_started_at IS NOT NULL AND o.status = 'in_process' AND v.status = 'published')
 THEN RAISE EXCEPTION 'Results require an explicitly started published line in an open processing order' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.revision <> 1 THEN RAISE EXCEPTION 'Capture revision must begin at one' USING ERRCODE = '23514'; END IF;
 ELSE
  IF (NEW.id, NEW.laboratory_id, NEW.laboratory_order_id, NEW.laboratory_order_exam_id, NEW.laboratory_exam_id, NEW.clinical_version_id, NEW.status)
   IS DISTINCT FROM (OLD.id, OLD.laboratory_id, OLD.laboratory_order_id, OLD.laboratory_order_exam_id, OLD.laboratory_exam_id, OLD.clinical_version_id, OLD.status)
   OR NEW.revision <> OLD.revision + 1
  THEN RAISE EXCEPTION 'Capture ownership is immutable and revision must advance exactly once' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_result_capture BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_result_captures FOR EACH ROW EXECUTE FUNCTION protect_result_capture();
CREATE OR REPLACE FUNCTION protect_result_parameter() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p jsonb; label text; current_revision bigint; line_id bigint;
BEGIN
 line_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.laboratory_order_exam_id ELSE NEW.laboratory_order_exam_id END;
 IF NOT EXISTS (SELECT 1 FROM laboratory_order_exams l JOIN laboratory_orders o ON o.id = l.laboratory_order_id
  WHERE l.id = line_id AND l.processing_started_at IS NOT NULL AND o.status = 'in_process')
 THEN RAISE EXCEPTION 'Results cannot change outside explicit clinical processing' USING ERRCODE = '23514'; END IF;
 SELECT revision INTO current_revision FROM laboratory_order_result_captures WHERE laboratory_order_exam_id = line_id AND status = 'preliminary';
 IF TG_OP = 'DELETE' THEN
  IF current_revision <= OLD.capture_revision THEN RAISE EXCEPTION 'Clearing a result requires an advanced capture revision' USING ERRCODE = '23514'; END IF;
  RETURN OLD;
 END IF;
 IF TG_OP = 'UPDATE' AND NEW.capture_revision <= OLD.capture_revision THEN RAISE EXCEPTION 'Editing a result requires an advanced capture revision' USING ERRCODE = '23514'; END IF;
 SELECT x INTO p FROM laboratory_exam_versions v CROSS JOIN LATERAL jsonb_array_elements(v.configuration->'parameters') x
  WHERE v.id = NEW.clinical_version_id AND v.status = 'published' AND x->>'code' = NEW.parameter_code;
 SELECT revision INTO current_revision FROM laboratory_order_result_captures WHERE laboratory_order_exam_id = NEW.laboratory_order_exam_id AND status = 'preliminary';
 IF p IS NULL OR NEW.parameter_snapshot IS DISTINCT FROM p OR NEW.value_type IS DISTINCT FROM p->>'type'
  OR NEW.capture_revision IS DISTINCT FROM current_revision
 THEN RAISE EXCEPTION 'Result must match its published historical parameter and current capture revision' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'UPDATE' AND (NEW.id, NEW.laboratory_id, NEW.laboratory_order_id, NEW.laboratory_order_exam_id, NEW.laboratory_exam_id, NEW.clinical_version_id, NEW.parameter_code, NEW.value_type, NEW.parameter_snapshot)
  IS DISTINCT FROM (OLD.id, OLD.laboratory_id, OLD.laboratory_order_id, OLD.laboratory_order_exam_id, OLD.laboratory_exam_id, OLD.clinical_version_id, OLD.parameter_code, OLD.value_type, OLD.parameter_snapshot)
 THEN RAISE EXCEPTION 'Result historical parameter identity is immutable' USING ERRCODE = '23514'; END IF;
 IF NEW.value_type = 'numeric' AND NEW.unit IS DISTINCT FROM p->>'unit'
 THEN RAISE EXCEPTION 'Result unit is fixed by its clinical version' USING ERRCODE = '23514'; END IF;
 IF NEW.value_type = 'text' AND length(NEW.text_value) > coalesce((p->>'text_max_length')::integer, 100000)
 THEN RAISE EXCEPTION 'Result exceeds the published text limit' USING ERRCODE = '23514'; END IF;
 IF NEW.value_type IN ('qualitative','predefined_options') THEN
  SELECT x->>'label' INTO label FROM jsonb_array_elements(p->'options') x WHERE x->>'code' = NEW.option_code;
  IF label IS NULL OR label IS DISTINCT FROM NEW.option_label THEN RAISE EXCEPTION 'Result option must match its historical code and label' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_result_parameter BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_results FOR EACH ROW EXECUTE FUNCTION protect_result_parameter();
CREATE OR REPLACE FUNCTION protect_result_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.event = 'order_results.saved' OR (TG_OP = 'UPDATE' AND NEW.event = 'order_results.saved')
 THEN RAISE EXCEPTION 'Clinical result audit history is immutable' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_result_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION protect_result_audit();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS protect_result_audit ON audit_logs');
        }
        Schema::dropIfExists('laboratory_order_results');
        Schema::dropIfExists('laboratory_order_result_captures');
    }
};
