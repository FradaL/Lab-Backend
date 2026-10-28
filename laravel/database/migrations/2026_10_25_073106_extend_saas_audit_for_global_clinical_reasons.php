<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['view' => 'Consultar catálogo e historial global.', 'create' => 'Crear identidad y primera versión global.', 'publish' => 'Publicar definiciones globales sin cambiar vigencia.', 'manage' => 'Cambiar explícitamente vigencia global.'] as $suffix => $description) {
            DB::table('saas_capabilities')->insert(['code' => 'saas.clinical_reasons.'.$suffix, 'description' => $description]);
        }
        Schema::table('global_clinical_reason_versions', function (Blueprint $t): void {
            $t->unique(['reason_id', 'id'], 'global_reason_version_subject_unique');
        });
        Schema::table('saas_audit_logs', function (Blueprint $t): void {
            $t->foreignId('global_reason_id')->nullable()->constrained('global_clinical_reasons')->restrictOnDelete();
            $t->unsignedBigInteger('global_reason_version_id')->nullable();
            $t->unsignedBigInteger('previous_global_reason_version_id')->nullable();
            $t->foreign(['global_reason_id', 'global_reason_version_id'], 'saas_audit_global_version_fk')->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $t->foreign(['global_reason_id', 'previous_global_reason_version_id'], 'saas_audit_previous_global_version_fk')->references(['reason_id', 'id'])->on('global_clinical_reason_versions')->restrictOnDelete();
            $t->index(['global_reason_id', 'id']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE global_clinical_reasons ALTER COLUMN created_at SET DEFAULT statement_timestamp();
ALTER TABLE global_clinical_reason_versions ALTER COLUMN created_at SET DEFAULT statement_timestamp();
ALTER TABLE saas_audit_logs ADD CONSTRAINT saas_catalog_audit_shape CHECK (
 (action IN ('saas.clinical_reasons.created','saas.clinical_reasons.published','saas.clinical_reasons.activation_changed')
 AND global_reason_id IS NOT NULL AND global_reason_version_id IS NOT NULL AND affected_user_id IS NULL
 AND actor_type='user' AND result='succeeded' AND capability_code IS NOT NULL
 AND ((action='saas.clinical_reasons.created' AND capability_code='saas.clinical_reasons.create' AND previous_global_reason_version_id IS NULL)
 OR (action='saas.clinical_reasons.published' AND capability_code='saas.clinical_reasons.publish' AND previous_global_reason_version_id IS NOT NULL)
 OR (action='saas.clinical_reasons.activation_changed' AND capability_code='saas.clinical_reasons.manage' AND previous_global_reason_version_id IS NOT NULL)))
 OR (action NOT IN ('saas.clinical_reasons.created','saas.clinical_reasons.published','saas.clinical_reasons.activation_changed')
 AND global_reason_id IS NULL AND global_reason_version_id IS NULL AND previous_global_reason_version_id IS NULL));
CREATE UNIQUE INDEX saas_one_catalog_event_per_version ON saas_audit_logs(global_reason_version_id) WHERE global_reason_version_id IS NOT NULL;
CREATE OR REPLACE FUNCTION verify_saas_catalog_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_version global_clinical_reason_versions; previous_version global_clinical_reason_versions;
BEGIN
 IF NEW.global_reason_id IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO current_version FROM global_clinical_reason_versions WHERE id=NEW.global_reason_version_id;
 IF current_version.reason_id IS DISTINCT FROM NEW.global_reason_id OR current_version.created_by IS DISTINCT FROM NEW.actor_id
 OR current_version.change_reason IS DISTINCT FROM NEW.justification
 OR NOT EXISTS(SELECT 1 FROM users u JOIN saas_capability_assignments a ON a.user_id=u.id WHERE u.id=NEW.actor_id AND u.is_active AND a.capability_code=NEW.capability_code AND a.revoked_at IS NULL)
 THEN RAISE EXCEPTION 'Matching authorized catalog audit required' USING ERRCODE='23514'; END IF;
 IF NEW.action='saas.clinical_reasons.created' THEN
  IF current_version.number<>1 OR NOT EXISTS(SELECT 1 FROM global_clinical_reasons WHERE id=NEW.global_reason_id AND created_by=NEW.actor_id)
  THEN RAISE EXCEPTION 'Creation must reference initial version' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO previous_version FROM global_clinical_reason_versions WHERE id=NEW.previous_global_reason_version_id;
  IF previous_version.reason_id IS DISTINCT FROM NEW.global_reason_id OR current_version.number IS DISTINCT FROM previous_version.number+1
  THEN RAISE EXCEPTION 'Catalog audit must reference predecessor' USING ERRCODE='23514'; END IF;
  IF NEW.action='saas.clinical_reasons.published' AND current_version.is_active IS DISTINCT FROM previous_version.is_active
  THEN RAISE EXCEPTION 'Publish cannot change activation' USING ERRCODE='23514'; END IF;
  IF NEW.action='saas.clinical_reasons.activation_changed' AND
   (current_version.is_active IS NOT DISTINCT FROM previous_version.is_active OR
    (to_jsonb(current_version)-ARRAY['id','number','is_active','created_by','created_at','change_reason']) IS DISTINCT FROM
    (to_jsonb(previous_version)-ARRAY['id','number','is_active','created_by','created_at','change_reason']))
  THEN RAISE EXCEPTION 'Activation may change only activation and provenance' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verify_saas_catalog_audit BEFORE INSERT ON saas_audit_logs FOR EACH ROW EXECUTE FUNCTION verify_saas_catalog_audit();
SQL);
    }

    public function down(): void
    {
        if (DB::table('saas_audit_logs')->whereNotNull('global_reason_id')->exists()
            || DB::table('saas_capability_assignments')->where('capability_code', 'like', 'saas.clinical_reasons.%')->exists()) {
            throw new RuntimeException('Global catalog governance history exists; use an explicit reviewed restoration strategy.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER verify_saas_catalog_audit ON saas_audit_logs; DROP FUNCTION verify_saas_catalog_audit(); DROP INDEX saas_one_catalog_event_per_version; ALTER TABLE saas_audit_logs DROP CONSTRAINT saas_catalog_audit_shape;');
        }
        Schema::table('saas_audit_logs', function (Blueprint $t): void {
            $t->dropForeign('saas_audit_global_version_fk');
            $t->dropForeign('saas_audit_previous_global_version_fk');
            $t->dropForeign(['global_reason_id']);
            $t->dropIndex(['global_reason_id', 'id']);
            $t->dropColumn(['global_reason_id', 'global_reason_version_id', 'previous_global_reason_version_id']);
        });
        Schema::table('global_clinical_reason_versions', fn (Blueprint $t) => $t->dropUnique('global_reason_version_subject_unique'));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE global_clinical_reasons ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP; ALTER TABLE global_clinical_reason_versions ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP; DROP TRIGGER protect_saas_capability ON saas_capabilities;');
        }
        DB::table('saas_capabilities')->whereIn('code', array_map(fn (string $suffix): string => 'saas.clinical_reasons.'.$suffix, ['view', 'create', 'publish', 'manage']))->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE TRIGGER protect_saas_capability BEFORE UPDATE OR DELETE ON saas_capabilities FOR EACH ROW EXECUTE FUNCTION protect_saas_append_only()');
        }
    }
};
