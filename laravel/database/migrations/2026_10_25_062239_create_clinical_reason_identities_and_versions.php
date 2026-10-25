<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['global', 'laboratory'] as $scope) {
            Schema::create($scope.'_clinical_reasons', function (Blueprint $table) use ($scope): void {
                $table->id();
                if ($scope === 'laboratory') {
                    $table->foreignId('laboratory_id')->constrained()->restrictOnDelete();
                    $table->unique(['laboratory_id', 'id'], 'local_reason_identity');
                }
                $table->string('code', 50);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampTz('created_at', 6)->useCurrent();
                $table->unique($scope === 'global' ? ['code'] : ['laboratory_id', 'code'], $scope.'_reason_code_unique');
                if ($scope === 'laboratory') {
                    $table->foreign(['laboratory_id', 'created_by'], 'local_reason_actor_fk')
                        ->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
                }
            });
            Schema::create($scope.'_clinical_reason_versions', function (Blueprint $table) use ($scope): void {
                $table->id();
                if ($scope === 'laboratory') {
                    $table->unsignedBigInteger('laboratory_id');
                }
                $table->unsignedBigInteger('reason_id');
                $table->unsignedBigInteger('number');
                $table->string('name', 200);
                $table->text('description');
                $table->string('classification', 30);
                $table->jsonb('evidence_requirements');
                $table->boolean('is_active');
                $table->text('change_reason');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampTz('created_at', 6)->useCurrent();
                $table->unique(['reason_id', 'number'], $scope.'_reason_version_unique');
                if ($scope === 'laboratory') {
                    $table->unique(['laboratory_id', 'reason_id', 'id'], 'local_reason_version_identity');
                    $table->foreign(['laboratory_id', 'reason_id'], 'local_reason_version_parent_fk')
                        ->references(['laboratory_id', 'id'])->on('laboratory_clinical_reasons')->restrictOnDelete();
                    $table->foreign(['laboratory_id', 'created_by'], 'local_reason_version_actor_fk')
                        ->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
                } else {
                    $table->foreign('reason_id')->references('id')->on('global_clinical_reasons')->restrictOnDelete();
                }
            });
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_reason_evidence_valid(document jsonb) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE requirement jsonb;
BEGIN
 IF jsonb_typeof(document) IS DISTINCT FROM 'object'
    OR document->'schema_version' IS DISTINCT FROM '1'::jsonb
    OR jsonb_typeof(document->'requirements') IS DISTINCT FROM 'array'
    OR document - 'schema_version' - 'requirements' <> '{}'::jsonb
 THEN RETURN false; END IF;
 FOR requirement IN SELECT value FROM jsonb_array_elements(document->'requirements') LOOP
  IF jsonb_typeof(requirement) <> 'string' OR (requirement #>> '{}') !~ '^[a-z][a-z0-9_]{0,99}$'
  THEN RETURN false; END IF;
 END LOOP;
 RETURN jsonb_array_length(document->'requirements') =
  (SELECT count(DISTINCT value) FROM jsonb_array_elements(document->'requirements'));
END $$;
CREATE OR REPLACE FUNCTION protect_clinical_reason_record() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Clinical reason identities and versions are immutable' USING ERRCODE='23514';
END $$;
CREATE OR REPLACE FUNCTION serialize_clinical_reason_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous_number bigint;
BEGIN
 IF TG_TABLE_NAME = 'global_clinical_reason_versions' THEN
  PERFORM 1 FROM global_clinical_reasons WHERE id=NEW.reason_id FOR UPDATE;
  SELECT coalesce(max(number),0) INTO previous_number FROM global_clinical_reason_versions WHERE reason_id=NEW.reason_id;
 ELSE
  PERFORM 1 FROM laboratory_clinical_reasons WHERE id=NEW.reason_id AND laboratory_id=NEW.laboratory_id FOR UPDATE;
  SELECT coalesce(max(number),0) INTO previous_number FROM laboratory_clinical_reason_versions WHERE reason_id=NEW.reason_id;
 END IF;
 IF NEW.number <> previous_number+1 THEN
  RAISE EXCEPTION 'Clinical reason version requires the next serialized number' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['global', 'laboratory'] as $scope) {
            DB::statement("ALTER TABLE {$scope}_clinical_reasons ADD CONSTRAINT {$scope}_reason_code_check CHECK(code ~ '^[a-z][a-z0-9_]{0,49}$')");
            DB::statement("ALTER TABLE {$scope}_clinical_reason_versions ADD CONSTRAINT {$scope}_reason_definition_check CHECK(number>0 AND length(btrim(name))>0 AND length(btrim(description))>0 AND classification IN ('incidence_only','terminal_candidate') AND clinical_reason_evidence_valid(evidence_requirements) AND length(btrim(change_reason))>0)");
            foreach (['reasons', 'reason_versions'] as $entity) {
                DB::statement("CREATE TRIGGER protect_{$scope}_clinical_{$entity} BEFORE UPDATE OR DELETE ON {$scope}_clinical_{$entity} FOR EACH ROW EXECUTE FUNCTION protect_clinical_reason_record()");
            }
            DB::statement("CREATE TRIGGER serialize_{$scope}_reason_version BEFORE INSERT ON {$scope}_clinical_reason_versions FOR EACH ROW EXECUTE FUNCTION serialize_clinical_reason_version()");
        }
    }

    public function down(): void
    {
        foreach (['global', 'laboratory'] as $scope) {
            if (DB::table($scope.'_clinical_reasons')->exists() || DB::table($scope.'_clinical_reason_versions')->exists()) {
                throw new RuntimeException('Clinical reason history exists; explicit backup/restoration is required.');
            }
        }
        foreach (['laboratory', 'global'] as $scope) {
            Schema::dropIfExists($scope.'_clinical_reason_versions');
            Schema::dropIfExists($scope.'_clinical_reasons');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION serialize_clinical_reason_version(); DROP FUNCTION protect_clinical_reason_record(); DROP FUNCTION clinical_reason_evidence_valid(jsonb);');
        }
    }
};
