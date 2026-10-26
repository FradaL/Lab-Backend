<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Local clinical integration requires PostgreSQL.');
        }
        Schema::create('clinical_exception_local_reason_references', function (Blueprint $t): void {
            $t->id();
            foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id', 'reason_id', 'version_id'] as $field) {
                $t->unsignedBigInteger($field);
            }
            $t->string('source', 20);
            $t->text('justification');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->default(DB::raw('statement_timestamp()'));
            $t->unique('exception_request_id', 'local_reference_request_unique');
            $t->unique(['laboratory_id', 'exception_request_id', 'reason_id', 'version_id'], 'local_reference_subject_unique');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id'], 'local_reference_request_fk')->references(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'id'])->on('clinical_exception_requests')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'reason_id', 'version_id'], 'local_reference_version_fk')->references(['laboratory_id', 'reason_id', 'id'])->on('laboratory_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'created_by'], 'local_reference_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('local_clinical_reason_reviews', function (Blueprint $t): void {
            $t->id();
            foreach (['laboratory_id', 'exception_request_id', 'reason_id', 'original_version_id', 'trigger_version_id'] as $field) {
                $t->unsignedBigInteger($field);
            }
            $t->string('cause_type', 30);
            $t->text('cause');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->default(DB::raw('statement_timestamp()'));
            $t->unique(['exception_request_id', 'trigger_version_id'], 'local_review_cause_unique');
            $t->unique(['laboratory_id', 'id'], 'local_review_tenant_identity');
            $t->foreign(['laboratory_id', 'exception_request_id', 'reason_id', 'original_version_id'], 'local_review_reference_fk')->references(['laboratory_id', 'exception_request_id', 'reason_id', 'version_id'])->on('clinical_exception_local_reason_references')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'reason_id', 'trigger_version_id'], 'local_review_version_fk')->references(['laboratory_id', 'reason_id', 'id'])->on('laboratory_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'created_by'], 'local_review_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('local_clinical_reason_resolutions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('review_id');
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->string('outcome', 20);
            $t->text('assessment');
            $t->text('justification');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->default(DB::raw('statement_timestamp()'));
            $t->unique(['review_id', 'revision'], 'local_resolution_revision_unique');
            $t->unique(['review_id', 'id'], 'local_resolution_identity');
            $t->foreign(['laboratory_id', 'review_id'], 'local_resolution_review_fk')->references(['laboratory_id', 'id'])->on('local_clinical_reason_reviews')->restrictOnDelete();
            $t->foreign(['review_id', 'previous_id'], 'local_resolution_previous_fk')->references(['review_id', 'id'])->on('local_clinical_reason_resolutions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'created_by'], 'local_resolution_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE clinical_exception_local_reason_references ADD CONSTRAINT local_reference_shape CHECK(source='local' AND length(btrim(justification))>0);
ALTER TABLE local_clinical_reason_reviews ADD CONSTRAINT local_review_shape CHECK(cause_type IN ('deactivation','reclassification') AND length(btrim(cause))>0);
ALTER TABLE local_clinical_reason_resolutions ADD CONSTRAINT local_resolution_shape CHECK(revision>0 AND outcome IN ('favorable','non_favorable') AND length(btrim(assessment))>0 AND length(btrim(justification))>0);
CREATE OR REPLACE FUNCTION lock_local_reason_writes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM pg_advisory_xact_lock(182045,1); RETURN NULL; END $$;
CREATE TRIGGER lock_local_reason_identity BEFORE INSERT ON laboratory_clinical_reasons FOR EACH STATEMENT EXECUTE FUNCTION lock_local_reason_writes();
CREATE TRIGGER lock_local_reason_version BEFORE INSERT ON laboratory_clinical_reason_versions FOR EACH STATEMENT EXECUTE FUNCTION lock_local_reason_writes();
CREATE OR REPLACE FUNCTION verify_local_exception_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM clinical_exception_legacy_requests WHERE exception_request_id=NEW.exception_request_id)
 OR NOT EXISTS(SELECT 1 FROM clinical_exception_requests q JOIN laboratory_clinical_reason_versions v ON v.id=NEW.version_id
  WHERE q.id=NEW.exception_request_id AND q.requested_by=NEW.created_by AND q.reason=v.name
  AND NOT EXISTS(SELECT 1 FROM laboratory_clinical_reason_versions newer WHERE newer.reason_id=v.reason_id AND newer.number>v.number))
 THEN RAISE EXCEPTION 'Exact local request, actor and current original version required' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verify_local_exception_reference BEFORE INSERT ON clinical_exception_local_reason_references FOR EACH ROW EXECUTE FUNCTION verify_local_exception_reference();
CREATE OR REPLACE FUNCTION assert_single_exception_origin(request_id bigint) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 IF (SELECT count(*) FROM clinical_exception_reason_references WHERE exception_request_id=request_id)
  +(SELECT count(*) FROM clinical_exception_local_reason_references WHERE exception_request_id=request_id) <> 1
 THEN RAISE EXCEPTION 'New requests require exactly one immutable reason origin' USING ERRCODE='23514'; END IF;
END $$;
CREATE OR REPLACE FUNCTION require_exception_reason_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM assert_single_exception_origin(NEW.id); RETURN NULL; END $$;
CREATE OR REPLACE FUNCTION require_exclusive_exception_origin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM assert_single_exception_origin(NEW.exception_request_id); RETURN NULL; END $$;
CREATE CONSTRAINT TRIGGER require_local_reason_exclusive AFTER INSERT ON clinical_exception_local_reason_references DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_exclusive_exception_origin();
CREATE CONSTRAINT TRIGGER require_global_reason_exclusive AFTER INSERT ON clinical_exception_reason_references DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_exclusive_exception_origin();
CREATE OR REPLACE FUNCTION verify_local_review_cause() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE version laboratory_clinical_reason_versions; previous laboratory_clinical_reason_versions;
BEGIN
 SELECT * INTO version FROM laboratory_clinical_reason_versions WHERE id=NEW.trigger_version_id;
 SELECT * INTO previous FROM laboratory_clinical_reason_versions WHERE reason_id=version.reason_id AND number=version.number-1;
 IF version.change_reason IS DISTINCT FROM NEW.cause OR version.created_by IS DISTINCT FROM NEW.created_by
 OR NOT EXISTS(SELECT 1 FROM clinical_exception_requests WHERE id=NEW.exception_request_id AND status='pending')
 OR NOT EXISTS(SELECT 1 FROM laboratory_clinical_reason_versions WHERE id=NEW.original_version_id AND number<version.number)
 OR NOT (NEW.cause_type='deactivation' AND previous.is_active=true AND version.is_active=false
      OR NEW.cause_type='reclassification' AND previous.classification IS DISTINCT FROM version.classification AND previous.id IS NOT NULL)
 THEN RAISE EXCEPTION 'A local review requires a genuine pending dependency and explicit version change' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verify_local_review_cause BEFORE INSERT ON local_clinical_reason_reviews FOR EACH ROW EXECUTE FUNCTION verify_local_review_cause();
CREATE OR REPLACE FUNCTION require_local_version_reviews() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior laboratory_clinical_reason_versions;
BEGIN
 SELECT * INTO prior FROM laboratory_clinical_reason_versions WHERE reason_id=NEW.reason_id AND number=NEW.number-1;
 IF prior.id IS NOT NULL AND (prior.classification IS DISTINCT FROM NEW.classification OR prior.is_active AND NOT NEW.is_active)
 AND EXISTS(SELECT 1 FROM clinical_exception_local_reason_references r JOIN clinical_exception_requests q ON q.id=r.exception_request_id
 JOIN laboratory_clinical_reason_versions original ON original.id=r.version_id
 WHERE r.reason_id=NEW.reason_id AND q.status='pending' AND original.number<NEW.number
 AND NOT EXISTS(SELECT 1 FROM local_clinical_reason_reviews review WHERE review.exception_request_id=q.id AND review.trigger_version_id=NEW.id))
 THEN RAISE EXCEPTION 'Local changes must preserve every pending review obligation' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER require_local_version_reviews AFTER INSERT ON laboratory_clinical_reason_versions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_local_version_reviews();
CREATE OR REPLACE FUNCTION serialize_local_review_resolution() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior local_clinical_reason_resolutions;
BEGIN
 PERFORM pg_advisory_xact_lock(182045,1);
 PERFORM 1 FROM local_clinical_reason_reviews WHERE id=NEW.review_id FOR UPDATE;
 SELECT * INTO prior FROM local_clinical_reason_resolutions WHERE review_id=NEW.review_id ORDER BY revision DESC LIMIT 1;
 IF prior.outcome='favorable' OR NEW.revision IS DISTINCT FROM coalesce(prior.revision,0)+1 OR NEW.previous_id IS DISTINCT FROM prior.id
 THEN RAISE EXCEPTION 'Local evaluation requires an unresolved exact predecessor' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER serialize_local_review_resolution BEFORE INSERT ON local_clinical_reason_resolutions FOR EACH ROW EXECUTE FUNCTION serialize_local_review_resolution();
SQL);
        foreach (['clinical_exception_local_reason_references', 'local_clinical_reason_reviews', 'local_clinical_reason_resolutions'] as $table) {
            DB::statement("CREATE TRIGGER protect_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION protect_clinical_reason_record()");
        }
    }

    public function down(): void
    {
        foreach (['clinical_exception_local_reason_references', 'local_clinical_reason_reviews', 'local_clinical_reason_resolutions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Local clinical history exists; explicit restoration required.');
            }
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER require_global_reason_exclusive ON clinical_exception_reason_references;
DROP TRIGGER require_local_version_reviews ON laboratory_clinical_reason_versions;
DROP TRIGGER lock_local_reason_identity ON laboratory_clinical_reasons;
DROP TRIGGER lock_local_reason_version ON laboratory_clinical_reason_versions;
CREATE OR REPLACE FUNCTION require_exception_reason_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM clinical_exception_reason_references WHERE exception_request_id=NEW.id)
 THEN RAISE EXCEPTION 'New clinical requests require a structured reason reference' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
SQL);
        Schema::drop('local_clinical_reason_resolutions');
        Schema::drop('local_clinical_reason_reviews');
        Schema::drop('clinical_exception_local_reason_references');
        DB::unprepared('DROP FUNCTION verify_local_exception_reference(); DROP FUNCTION verify_local_review_cause(); DROP FUNCTION require_local_version_reviews(); DROP FUNCTION serialize_local_review_resolution(); DROP FUNCTION require_exclusive_exception_origin(); DROP FUNCTION assert_single_exception_origin(bigint); DROP FUNCTION lock_local_reason_writes();');
    }
};
