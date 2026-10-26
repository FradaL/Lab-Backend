<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_reason_adoptions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('laboratory_id')->constrained()->restrictOnDelete();
            $t->foreignId('reason_id')->constrained('global_clinical_reasons')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['laboratory_id', 'reason_id']);
            $t->unique(['laboratory_id', 'reason_id', 'id'], 'adoption_subject_unique');
            $t->foreign(['laboratory_id', 'created_by'], 'adoption_owner_member_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('clinical_reason_adoption_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('reason_id');
            $t->unsignedBigInteger('adoption_id');
            $t->unsignedBigInteger('version_id');
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->string('status')->default('adopted');
            $t->text('justification');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['adoption_id', 'revision']);
            $t->unique(['adoption_id', 'id']);
            $t->foreign(['laboratory_id', 'reason_id', 'adoption_id'], 'adopt_version_parent_fk')->references(['laboratory_id', 'reason_id', 'id'])->on('clinical_reason_adoptions')->restrictOnDelete();
            $t->foreign(['reason_id', 'version_id'], 'adopt_version_global_fk')->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['adoption_id', 'previous_id'], 'adopt_version_previous_fk')->references(['adoption_id', 'id'])->on('clinical_reason_adoption_versions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'created_by'], 'adopt_version_member_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('clinical_reason_impact_reviews', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('reason_id');
            $t->unsignedBigInteger('adoption_id');
            $t->unsignedBigInteger('adopted_version_id');
            $t->unsignedBigInteger('trigger_version_id');
            $t->unsignedBigInteger('impact_id')->nullable();
            $t->string('cause_type', 20);
            $t->text('cause');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['adoption_id', 'impact_id']);
            $t->unique(['laboratory_id', 'id']);
            $t->foreign(['laboratory_id', 'reason_id', 'adoption_id'], 'impact_review_adoption_fk')->references(['laboratory_id', 'reason_id', 'id'])->on('clinical_reason_adoptions')->restrictOnDelete();
            $t->foreign(['reason_id', 'adopted_version_id'], 'impact_review_original_fk')->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['reason_id', 'trigger_version_id'], 'impact_review_trigger_fk')->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['reason_id', 'impact_id'], 'impact_review_declaration_fk')->references(['reason_id', 'id'])->on('global_clinical_reason_impacts')->restrictOnDelete();
        });
        Schema::create('clinical_reason_impact_resolutions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('review_id');
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->string('outcome', 20);
            $t->text('assessment');
            $t->text('justification');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['review_id', 'revision']);
            $t->unique(['review_id', 'id']);
            $t->foreign(['laboratory_id', 'review_id'], 'impact_resolution_review_fk')->references(['laboratory_id', 'id'])->on('clinical_reason_impact_reviews')->restrictOnDelete();
            $t->foreign(['review_id', 'previous_id'], 'impact_resolution_previous_fk')->references(['review_id', 'id'])->on('clinical_reason_impact_resolutions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'created_by'], 'impact_resolution_member_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX one_deactivation_review ON clinical_reason_impact_reviews(adoption_id,trigger_version_id) WHERE cause_type='deactivation';
ALTER TABLE clinical_reason_adoption_versions ADD CONSTRAINT adoption_version_shape CHECK(status='adopted' AND revision>0 AND length(btrim(justification))>0);
ALTER TABLE clinical_reason_impact_reviews ADD CONSTRAINT impact_review_shape CHECK(length(btrim(cause))>0 AND (cause_type='impact' AND impact_id IS NOT NULL OR cause_type='deactivation' AND impact_id IS NULL));
ALTER TABLE clinical_reason_impact_resolutions ADD CONSTRAINT impact_resolution_shape CHECK(revision>0 AND outcome IN ('favorable','non_favorable') AND length(btrim(assessment))>0 AND length(btrim(justification))>0);
CREATE OR REPLACE FUNCTION serialize_adoption_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior_id bigint; prior_revision integer; prior_version bigint; prior_outcome text;
BEGIN
 PERFORM pg_advisory_xact_lock(182045,1);
 IF TG_TABLE_NAME='clinical_reason_adoption_versions' THEN
  PERFORM 1 FROM clinical_reason_adoptions WHERE id=NEW.adoption_id FOR UPDATE;
  SELECT id,revision,version_id INTO prior_id,prior_revision,prior_version FROM clinical_reason_adoption_versions WHERE adoption_id=NEW.adoption_id ORDER BY revision DESC LIMIT 1;
  IF prior_version IS NOT NULL AND (SELECT number FROM global_clinical_reason_versions WHERE id=NEW.version_id)<=(SELECT number FROM global_clinical_reason_versions WHERE id=prior_version)
  THEN RAISE EXCEPTION 'Adoption updates require a later global version' USING ERRCODE='23514'; END IF;
  IF NEW.version_id IS NOT DISTINCT FROM prior_version OR NEW.revision IS DISTINCT FROM coalesce(prior_revision,0)+1 OR NEW.previous_id IS DISTINCT FROM prior_id
  THEN RAISE EXCEPTION 'Adoption requires exact predecessor and changed version' USING ERRCODE='23514'; END IF;
 ELSE
  PERFORM 1 FROM clinical_reason_impact_reviews WHERE id=NEW.review_id FOR UPDATE;
  SELECT id,revision,outcome INTO prior_id,prior_revision,prior_outcome FROM clinical_reason_impact_resolutions WHERE review_id=NEW.review_id ORDER BY revision DESC LIMIT 1;
  IF prior_outcome='favorable' OR NEW.revision IS DISTINCT FROM coalesce(prior_revision,0)+1 OR NEW.previous_id IS DISTINCT FROM prior_id
  THEN RAISE EXCEPTION 'Review requires unresolved exact predecessor' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER serialize_adoption_version BEFORE INSERT ON clinical_reason_adoption_versions FOR EACH ROW EXECUTE FUNCTION serialize_adoption_record();
CREATE TRIGGER serialize_impact_resolution BEFORE INSERT ON clinical_reason_impact_resolutions FOR EACH ROW EXECUTE FUNCTION serialize_adoption_record();
CREATE OR REPLACE FUNCTION verify_review_cause() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE declaration global_clinical_reason_impacts; version global_clinical_reason_versions;
BEGIN
 IF NEW.cause_type='impact' THEN
  SELECT * INTO declaration FROM global_clinical_reason_impacts WHERE id=NEW.impact_id;
  IF declaration.classification IS DISTINCT FROM 'restrictive' OR declaration.version_id IS DISTINCT FROM NEW.trigger_version_id OR declaration.justification IS DISTINCT FROM NEW.cause
  THEN RAISE EXCEPTION 'Review requires matching restrictive declaration' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO version FROM global_clinical_reason_versions WHERE id=NEW.trigger_version_id;
  IF version.is_active IS DISTINCT FROM false OR version.change_reason IS DISTINCT FROM NEW.cause
  THEN RAISE EXCEPTION 'Review requires matching deactivation' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verify_review_cause BEFORE INSERT ON clinical_reason_impact_reviews FOR EACH ROW EXECUTE FUNCTION verify_review_cause();
SQL);
        foreach (['clinical_reason_adoptions', 'clinical_reason_adoption_versions', 'clinical_reason_impact_reviews', 'clinical_reason_impact_resolutions'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN created_at SET DEFAULT statement_timestamp()");
            DB::statement("CREATE TRIGGER protect_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION protect_clinical_reason_record()");
        }
    }

    public function down(): void
    {
        foreach (['clinical_reason_impact_resolutions', 'clinical_reason_impact_reviews', 'clinical_reason_adoption_versions', 'clinical_reason_adoptions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Adoption or review history exists; explicit restoration required.');
            }
        }
        foreach (['clinical_reason_impact_resolutions', 'clinical_reason_impact_reviews', 'clinical_reason_adoption_versions', 'clinical_reason_adoptions'] as $table) {
            Schema::drop($table);
        }
        DB::unprepared('DROP FUNCTION serialize_adoption_record(); DROP FUNCTION verify_review_cause();');
    }
};
