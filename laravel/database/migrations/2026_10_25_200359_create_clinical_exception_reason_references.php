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
            throw new RuntimeException('Structured clinical exceptions require PostgreSQL.');
        }
        // Freeze the finite set of pre-integration requests without changing their rows.
        DB::statement('LOCK TABLE clinical_exception_requests IN SHARE ROW EXCLUSIVE MODE');
        Schema::create('clinical_exception_legacy_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('exception_request_id')->primary();
            $table->foreign('exception_request_id')->references('id')->on('clinical_exception_requests')->restrictOnDelete();
        });
        DB::statement('INSERT INTO clinical_exception_legacy_requests SELECT id FROM clinical_exception_requests');
        Schema::create('clinical_exception_reason_references', function (Blueprint $table): void {
            $table->id();
            foreach (['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id', 'reason_id', 'version_id', 'adoption_id', 'adoption_version_id'] as $field) {
                $table->unsignedBigInteger($field);
            }
            $table->string('source', 20);
            $table->text('justification');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at', 6)->default(DB::raw('statement_timestamp()'));
            $table->unique('exception_request_id');
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'exception_request_id'], 'exception_reason_request_fk')->references(['laboratory_id', 'laboratory_order_id', 'laboratory_order_exam_id', 'id'])->on('clinical_exception_requests')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'reason_id', 'adoption_id'], 'exception_reason_adoption_fk')->references(['laboratory_id', 'reason_id', 'id'])->on('clinical_reason_adoptions')->restrictOnDelete();
            $table->foreign(['adoption_id', 'adoption_version_id'], 'exception_reason_selection_fk')->references(['adoption_id', 'id'])->on('clinical_reason_adoption_versions')->restrictOnDelete();
            $table->foreign(['reason_id', 'version_id'], 'exception_reason_version_fk')->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'created_by'], 'exception_reason_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE clinical_exception_reason_references ADD CONSTRAINT exception_reason_shape CHECK(source='global' AND length(btrim(justification))>0);
CREATE OR REPLACE FUNCTION protect_exception_legacy_set() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'The pre-integration request set is sealed' USING ERRCODE='23514'; END $$;
CREATE TRIGGER protect_exception_legacy_set BEFORE INSERT OR UPDATE OR DELETE ON clinical_exception_legacy_requests FOR EACH ROW EXECUTE FUNCTION protect_exception_legacy_set();
CREATE OR REPLACE FUNCTION verify_exception_reason_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Clinical reason references are immutable' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM clinical_exception_legacy_requests WHERE exception_request_id=NEW.exception_request_id)
 OR NOT EXISTS(SELECT 1 FROM clinical_exception_requests q JOIN global_clinical_reason_versions v ON v.id=NEW.version_id
   JOIN clinical_reason_adoption_versions s ON s.id=NEW.adoption_version_id
   WHERE q.id=NEW.exception_request_id AND q.requested_by=NEW.created_by AND q.reason=v.name
   AND s.adoption_id=NEW.adoption_id AND s.version_id=NEW.version_id
   AND NOT EXISTS(SELECT 1 FROM clinical_reason_adoption_versions newer WHERE newer.adoption_id=s.adoption_id AND newer.revision>s.revision))
 THEN RAISE EXCEPTION 'Exact original request, actor, definition and current adopted selection required' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verify_exception_reason_reference BEFORE INSERT OR UPDATE OR DELETE ON clinical_exception_reason_references FOR EACH ROW EXECUTE FUNCTION verify_exception_reason_reference();
CREATE OR REPLACE FUNCTION require_exception_reason_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM clinical_exception_reason_references WHERE exception_request_id=NEW.id)
 THEN RAISE EXCEPTION 'New clinical requests require a structured reason reference' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER require_exception_reason_reference AFTER INSERT ON clinical_exception_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_exception_reason_reference();
SQL);
    }

    public function down(): void
    {
        if (DB::table('clinical_exception_reason_references')->exists()) {
            throw new RuntimeException('Structured clinical history exists; explicit restoration required.');
        }
        DB::unprepared('DROP TRIGGER require_exception_reason_reference ON clinical_exception_requests; DROP FUNCTION require_exception_reason_reference();');
        Schema::drop('clinical_exception_reason_references');
        Schema::drop('clinical_exception_legacy_requests');
        DB::unprepared('DROP FUNCTION verify_exception_reason_reference(); DROP FUNCTION protect_exception_legacy_set();');
    }
};
