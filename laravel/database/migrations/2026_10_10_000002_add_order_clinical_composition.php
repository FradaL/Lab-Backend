<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_order_legacy_exams', function (Blueprint $table): void {
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_exam_id');
            $table->timestampTz('authorized_at');
            $table->primary(['laboratory_id', 'laboratory_exam_id'], 'order_legacy_exam_pk');
            $table->foreign(['laboratory_id', 'laboratory_exam_id'], 'order_legacy_exam_fk')
                ->references(['laboratory_id', 'id'])->on('laboratory_exams')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE laboratory_exams, laboratory_exam_clinical_states IN SHARE ROW EXCLUSIVE MODE');
        }
        DB::table('laboratory_order_legacy_exams')->insertUsing(
            ['laboratory_id', 'laboratory_exam_id', 'authorized_at'],
            DB::table('laboratory_exams')->select(['laboratory_id', 'id', DB::raw('CURRENT_TIMESTAMP')])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('laboratory_exam_clinical_states')
                    ->whereColumn('laboratory_exam_clinical_states.laboratory_exam_id', 'laboratory_exams.id')),
        );

        Schema::table('laboratory_order_exams', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinical_version_id')->nullable();
            $table->string('clinical_configuration_source', 30)->nullable();
            $table->timestampTz('clinical_activity_started_at')->nullable();
            $table->foreign(['laboratory_id', 'laboratory_exam_id', 'clinical_version_id'], 'order_exam_clinical_version_fk')
                ->references(['laboratory_id', 'laboratory_exam_id', 'id'])->on('laboratory_exam_versions')->restrictOnDelete();
            $table->unique(['laboratory_id', 'laboratory_order_id', 'id', 'laboratory_exam_id', 'clinical_version_id'], 'order_exam_clinical_identity_unique');
        });

        Schema::create('laboratory_order_sample_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_order_id');
            $table->unsignedBigInteger('laboratory_order_exam_id');
            $table->unsignedBigInteger('laboratory_exam_id');
            $table->unsignedBigInteger('clinical_version_id');
            $table->string('requirement_key', 60);
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('sample_type_id');
            $table->jsonb('configuration');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('clinical_activity_started_at')->nullable();
            $table->timestamps();
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'laboratory_exam_id', 'clinical_version_id'], 'order_requirement_line_fk')
                ->references(['laboratory_id', 'laboratory_order_id', 'id', 'laboratory_exam_id', 'clinical_version_id'])
                ->on('laboratory_order_exams')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'sample_type_id'], 'order_requirement_sample_type_fk')
                ->references(['laboratory_id', 'id'])->on('sample_types')->restrictOnDelete();
            $table->unique(['laboratory_order_exam_id', 'requirement_key'], 'order_requirement_key_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_order_legacy_exam() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Legacy order eligibility is a closed migration snapshot' USING ERRCODE = '23514'; END $$;
CREATE TRIGGER protect_order_legacy_exam BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_legacy_exams
 FOR EACH ROW EXECUTE FUNCTION protect_order_legacy_exam();

ALTER TABLE laboratory_order_exams ADD CONSTRAINT order_exam_clinical_source_check CHECK (
 (clinical_version_id IS NULL AND (clinical_configuration_source IS NULL OR clinical_configuration_source = 'legacy_compatibility'))
 OR (clinical_version_id IS NOT NULL AND clinical_configuration_source IS NOT NULL AND clinical_configuration_source = 'published_version'));
CREATE OR REPLACE FUNCTION protect_order_exam_clinical_identity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_status text;
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.clinical_version_id IS NULL THEN
   IF NEW.clinical_configuration_source IS DISTINCT FROM 'legacy_compatibility' OR NOT EXISTS (
    SELECT 1 FROM laboratory_order_legacy_exams WHERE laboratory_id = NEW.laboratory_id AND laboratory_exam_id = NEW.laboratory_exam_id
   ) OR EXISTS (
    SELECT 1 FROM laboratory_exam_versions WHERE laboratory_id = NEW.laboratory_id AND laboratory_exam_id = NEW.laboratory_exam_id AND status = 'published'
   ) THEN RAISE EXCEPTION 'A new line requires a published version or explicit legacy eligibility' USING ERRCODE = '23514'; END IF;
  ELSE
   SELECT status INTO v_status FROM laboratory_exam_versions WHERE id = NEW.clinical_version_id;
   IF v_status IS DISTINCT FROM 'published' THEN RAISE EXCEPTION 'Order lines require published versions' USING ERRCODE = '23514'; END IF;
  END IF;
 END IF;
 IF TG_OP = 'UPDATE' THEN
  IF (NEW.laboratory_id, NEW.laboratory_order_id, NEW.laboratory_exam_id, NEW.clinical_version_id, NEW.clinical_configuration_source)
   IS DISTINCT FROM (OLD.laboratory_id, OLD.laboratory_order_id, OLD.laboratory_exam_id, OLD.clinical_version_id, OLD.clinical_configuration_source)
   THEN RAISE EXCEPTION 'Order clinical identity is immutable' USING ERRCODE = '23514'; END IF;
  IF OLD.clinical_activity_started_at IS NOT NULL AND NEW.clinical_activity_started_at IS DISTINCT FROM OLD.clinical_activity_started_at
   THEN RAISE EXCEPTION 'Clinical activity cannot be erased' USING ERRCODE = '23514'; END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN
  IF OLD.clinical_activity_started_at IS NOT NULL THEN RAISE EXCEPTION 'Cannot delete a line with clinical activity' USING ERRCODE = '23514'; END IF;
  RETURN OLD;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_order_exam_clinical_identity BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_exams
 FOR EACH ROW EXECUTE FUNCTION protect_order_exam_clinical_identity();

ALTER TABLE laboratory_order_sample_requirements ADD CONSTRAINT order_requirement_values_check
 CHECK (position >= 0 AND status = 'pending' AND jsonb_typeof(configuration) = 'object');
CREATE OR REPLACE FUNCTION protect_order_sample_requirement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_document jsonb;
BEGIN
 IF TG_OP = 'INSERT' THEN
  SELECT configuration INTO v_document FROM laboratory_exam_versions WHERE id = NEW.clinical_version_id AND status = 'published';
  IF v_document->'sample_mode' IS DISTINCT FROM '"physical"'::jsonb OR
   (v_document->'sample_requirements'->NEW.position) IS DISTINCT FROM NEW.configuration OR
   NEW.configuration->>'key' IS DISTINCT FROM NEW.requirement_key OR
   NEW.configuration->>'sample_type_id' IS DISTINCT FROM NEW.sample_type_id::text
   THEN RAISE EXCEPTION 'A requirement must match its published configuration' USING ERRCODE = '23514'; END IF;
 END IF;
 IF TG_OP = 'UPDATE' THEN
  IF (NEW.laboratory_id, NEW.laboratory_order_id, NEW.laboratory_order_exam_id, NEW.laboratory_exam_id, NEW.clinical_version_id, NEW.requirement_key, NEW.position, NEW.sample_type_id, NEW.configuration)
   IS DISTINCT FROM (OLD.laboratory_id, OLD.laboratory_order_id, OLD.laboratory_order_exam_id, OLD.laboratory_exam_id, OLD.clinical_version_id, OLD.requirement_key, OLD.position, OLD.sample_type_id, OLD.configuration)
   THEN RAISE EXCEPTION 'Requirement origin and snapshots are immutable' USING ERRCODE = '23514'; END IF;
  IF OLD.clinical_activity_started_at IS NOT NULL AND NEW.clinical_activity_started_at IS DISTINCT FROM OLD.clinical_activity_started_at
   THEN RAISE EXCEPTION 'Clinical activity cannot be erased' USING ERRCODE = '23514'; END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN
  IF OLD.clinical_activity_started_at IS NOT NULL THEN RAISE EXCEPTION 'Cannot delete a requirement with clinical activity' USING ERRCODE = '23514'; END IF;
  RETURN OLD;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_order_sample_requirement BEFORE INSERT OR UPDATE OR DELETE ON laboratory_order_sample_requirements
 FOR EACH ROW EXECUTE FUNCTION protect_order_sample_requirement();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_order_sample_requirements');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS protect_order_exam_clinical_identity ON laboratory_order_exams');
            DB::statement('ALTER TABLE laboratory_order_exams DROP CONSTRAINT IF EXISTS order_exam_clinical_source_check');
        }
        Schema::table('laboratory_order_exams', function (Blueprint $table): void {
            $table->dropForeign('order_exam_clinical_version_fk');
            $table->dropUnique('order_exam_clinical_identity_unique');
            $table->dropColumn(['clinical_version_id', 'clinical_configuration_source', 'clinical_activity_started_at']);
        });
        Schema::dropIfExists('laboratory_order_legacy_exams');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_order_legacy_exam(); DROP FUNCTION IF EXISTS protect_order_exam_clinical_identity(); DROP FUNCTION IF EXISTS protect_order_sample_requirement();');
        }
    }
};
