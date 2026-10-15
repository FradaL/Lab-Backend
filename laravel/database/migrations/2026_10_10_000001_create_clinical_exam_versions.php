<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_exams', function (Blueprint $table): void {
            $table->unsignedBigInteger('sample_type_id')->nullable()->change();
        });
        Schema::create('global_exam_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 150);
            $table->jsonb('configuration');
            $table->timestamps();
        });
        Schema::create('laboratory_exam_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_exam_id');
            $table->unsignedInteger('number');
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('base_version_id')->nullable();
            $table->jsonb('configuration');
            $table->string('configuration_hash', 64)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['laboratory_id', 'laboratory_exam_id'], 'clinical_version_exam_fk')
                ->references(['laboratory_id', 'id'])->on('laboratory_exams')->restrictOnDelete();
            $table->unique(['laboratory_exam_id', 'number'], 'clinical_version_number_unique');
            $table->unique(['laboratory_id', 'laboratory_exam_id', 'id'], 'clinical_version_tenant_unique');
            $table->foreign(['laboratory_id', 'laboratory_exam_id', 'base_version_id'], 'clinical_version_base_fk')
                ->references(['laboratory_id', 'laboratory_exam_id', 'id'])->on('laboratory_exam_versions')->restrictOnDelete();
        });
        Schema::create('laboratory_exam_clinical_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_exam_id')->unique();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('global_exam_template_id')->nullable()->constrained('global_exam_templates')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['laboratory_id', 'laboratory_exam_id'], 'clinical_state_exam_fk')
                ->references(['laboratory_id', 'id'])->on('laboratory_exams')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'laboratory_exam_id', 'current_version_id'], 'clinical_state_version_fk')
                ->references(['laboratory_id', 'laboratory_exam_id', 'id'])->on('laboratory_exam_versions')->restrictOnDelete();
            $table->unique(['laboratory_id', 'global_exam_template_id'], 'clinical_template_import_unique');
        });
        Schema::table('laboratory_exam_versions', function (Blueprint $table): void {
            $table->foreign('laboratory_exam_id', 'clinical_version_enrollment_fk')
                ->references('laboratory_exam_id')->on('laboratory_exam_clinical_states')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE laboratory_exam_versions ADD CONSTRAINT clinical_version_state_check CHECK (
 number > 0 AND revision > 0 AND jsonb_typeof(configuration) = 'object' AND (
 (status = 'draft' AND published_at IS NULL AND published_by IS NULL AND configuration_hash IS NULL) OR
 (status = 'published' AND published_at IS NOT NULL AND published_by IS NOT NULL AND configuration_hash ~ '^[a-f0-9]{64}$')));
ALTER TABLE global_exam_templates ADD CONSTRAINT clinical_template_document_check CHECK (jsonb_typeof(configuration) = 'object');
CREATE OR REPLACE FUNCTION protect_clinical_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' AND OLD.status = 'published' THEN
  RAISE EXCEPTION 'Published clinical versions are immutable' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'INSERT' AND NEW.status <> 'draft' THEN
  RAISE EXCEPTION 'Clinical versions must start as drafts' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'UPDATE' AND (NEW.laboratory_id, NEW.laboratory_exam_id, NEW.number, NEW.base_version_id)
   IS DISTINCT FROM (OLD.laboratory_id, OLD.laboratory_exam_id, OLD.number, OLD.base_version_id) THEN
  RAISE EXCEPTION 'Clinical version ownership and lineage are immutable' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_version BEFORE INSERT OR UPDATE OR DELETE ON laboratory_exam_versions
 FOR EACH ROW EXECUTE FUNCTION protect_clinical_version();
CREATE OR REPLACE FUNCTION protect_clinical_state() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_status text;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Clinical enrollment cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'UPDATE' AND (NEW.laboratory_id, NEW.laboratory_exam_id, NEW.global_exam_template_id)
  IS DISTINCT FROM (OLD.laboratory_id, OLD.laboratory_exam_id, OLD.global_exam_template_id) THEN
  RAISE EXCEPTION 'Clinical enrollment and provenance are immutable' USING ERRCODE = '23514';
 END IF;
 IF NEW.current_version_id IS NOT NULL THEN
  SELECT status INTO v_status FROM laboratory_exam_versions WHERE id = NEW.current_version_id FOR SHARE;
  IF v_status IS DISTINCT FROM 'published' THEN RAISE EXCEPTION 'Current version must be published' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_clinical_state BEFORE INSERT OR UPDATE OR DELETE ON laboratory_exam_clinical_states
 FOR EACH ROW EXECUTE FUNCTION protect_clinical_state();
CREATE OR REPLACE FUNCTION protect_global_exam_template() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Global template artifacts are immutable' USING ERRCODE = '23514'; END $$;
CREATE TRIGGER protect_global_exam_template BEFORE UPDATE OR DELETE ON global_exam_templates
 FOR EACH ROW EXECUTE FUNCTION protect_global_exam_template();
SQL);
        }
    }

    public function down(): void
    {
        Schema::table('laboratory_exam_versions', fn (Blueprint $table) => $table->dropForeign('clinical_version_enrollment_fk'));
        Schema::dropIfExists('laboratory_exam_clinical_states');
        Schema::dropIfExists('laboratory_exam_versions');
        Schema::dropIfExists('global_exam_templates');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_clinical_state(); DROP FUNCTION IF EXISTS protect_clinical_version(); DROP FUNCTION IF EXISTS protect_global_exam_template();');
        }
        // Keep sample_type_id nullable: reverting it would destroy valid sample-free records.
    }
};
