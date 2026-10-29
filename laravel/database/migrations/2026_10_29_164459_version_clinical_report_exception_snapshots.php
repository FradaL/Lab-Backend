<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_report_issuances', fn (Blueprint $t) => $t->jsonb('pending_snapshot')->nullable());
        $originalSnapshot = DB::scalar("SELECT pg_get_functiondef('report_line_snapshot(bigint)'::regprocedure)");
        $originalGuard = DB::scalar("SELECT pg_get_functiondef('protect_report_issuance()'::regprocedure)");
        DB::unprepared(str_replace('FUNCTION public.report_line_snapshot(', 'FUNCTION public.report_line_snapshot_v1(', $originalSnapshot));
        DB::unprepared(str_replace('FUNCTION public.protect_report_issuance(', 'FUNCTION public.protect_report_issuance_v1(', $originalGuard));
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION report_line_snapshot(target_line bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
 WITH original AS (SELECT report_line_snapshot_v1(target_line) AS s), reference AS (
 SELECT jsonb_build_object('source','global','reference_id',r.id,'reason_id',r.reason_id,'code',i.code,'version_id',r.version_id,'version_number',v.number,'definition',to_jsonb(v),'adoption_id',r.adoption_id,'adoption_version_id',r.adoption_version_id) AS value
 FROM original o JOIN clinical_exception_reason_references r ON r.exception_request_id=(o.s->'exception'->'request'->>'id')::bigint
 JOIN global_clinical_reason_versions v ON v.id=r.version_id JOIN global_clinical_reasons i ON i.id=r.reason_id
 UNION ALL
 SELECT jsonb_build_object('source','local','reference_id',r.id,'reason_id',r.reason_id,'code',i.code,'version_id',r.version_id,'version_number',v.number,'definition',to_jsonb(v))
 FROM original o JOIN clinical_exception_local_reason_references r ON r.exception_request_id=(o.s->'exception'->'request'->>'id')::bigint
 JOIN laboratory_clinical_reason_versions v ON v.id=r.version_id JOIN laboratory_clinical_reasons i ON i.id=r.reason_id
 )
 SELECT s || jsonb_build_object('snapshot_version',2,'clinical_display',jsonb_build_object(
 'status',CASE WHEN s->'validation'<>'null'::jsonb THEN 'validated_result' WHEN reference.value IS NOT NULL THEN 'not_performed' ELSE 'authorized_exception' END,
 'label',CASE WHEN s->'validation'<>'null'::jsonb THEN 'Resultado validado' WHEN reference.value IS NOT NULL THEN 'No realizado' ELSE 'Excepción clínica autorizada' END,
 'reason',CASE WHEN reference.value IS NOT NULL THEN reference.value->'definition'->>'name' WHEN s->'exception'<>'null'::jsonb THEN s->'exception'->'request'->>'reason' ELSE NULL END),
 'exception',CASE WHEN s->'exception'='null'::jsonb THEN NULL ELSE (s->'exception') || jsonb_build_object('reason_reference',reference.value,
 'evidence_review_id',(SELECT id FROM clinical_exception_evidence_reviews WHERE exception_request_id=(s->'exception'->'request'->>'id')::bigint ORDER BY id DESC LIMIT 1)) END)
 FROM original LEFT JOIN reference ON true WHERE s IS NOT NULL
$$;
CREATE OR REPLACE FUNCTION report_pending_snapshot(target_order bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT coalesce(jsonb_agg(jsonb_build_object('line_id',l.id,'exam_code',l.exam_code,'exam_name',l.exam_name,
 'clinical_status',CASE WHEN EXISTS(SELECT 1 FROM clinical_line_evidence e WHERE e.laboratory_order_exam_id=l.id)
 OR EXISTS(SELECT 1 FROM clinical_replacement_intents i WHERE i.laboratory_order_exam_id=l.id) THEN 'incomplete' ELSE 'pending' END,
 'processing_started',l.processing_started_at IS NOT NULL) ORDER BY l.id),'[]'::jsonb)
 FROM laboratory_order_exams l WHERE l.laboratory_order_id=target_order AND NOT EXISTS(SELECT 1 FROM clinical_line_resolutions r WHERE r.laboratory_order_exam_id=l.id)
$$;
ALTER TABLE laboratory_report_issuances ADD CONSTRAINT report_pending_shape CHECK(
 (format_version='donqer-clinical-1' AND pending_snapshot IS NULL) OR (format_version='donqer-clinical-2' AND pending_snapshot IS NOT NULL AND jsonb_typeof(pending_snapshot)='array'));
SQL);
        $this->formats(true);
        $guard = str_replace("IF TG_TABLE_NAME='laboratory_report_issuance_lines' THEN", "IF TG_TABLE_NAME='laboratory_report_issuance_lines' THEN\n  IF NOT EXISTS(SELECT 1 FROM laboratory_report_issuances WHERE id=NEW.report_id AND format_version='donqer-clinical-2') THEN RAISE EXCEPTION 'Historical report lines cannot be extended' USING ERRCODE='23514'; END IF;", $originalGuard, $lineChanges);
        $guard = str_replace('SELECT * INTO o FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;', "SELECT * INTO o FROM laboratory_orders WHERE id=NEW.laboratory_order_id FOR UPDATE;\n IF NEW.format_version IS DISTINCT FROM 'donqer-clinical-2' OR NEW.pending_snapshot IS DISTINCT FROM (CASE WHEN NEW.type='partial' THEN report_pending_snapshot(o.id) ELSE '[]'::jsonb END) THEN RAISE EXCEPTION 'New report requires current versioned clinical and pending evidence' USING ERRCODE='23514'; END IF;", $guard, $headerChanges);
        if ($lineChanges !== 1 || $headerChanges !== 1) {
            throw new LogicException('Review the report integrity guard before extending its contract.');
        }
        DB::unprepared($guard);
    }

    private function formats(bool $new): void
    {
        $definition = DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='laboratory_report_issuances'::regclass AND conname='report_values'");
        if ($new) {
            $definition = str_replace("(format_version)::text = 'donqer-clinical-1'::text", "(format_version)::text = ANY (ARRAY['donqer-clinical-1'::text,'donqer-clinical-2'::text])", $definition, $changes);
            if ($changes !== 1) {
                throw new LogicException('Review the report format constraint before extending it.');
            }
        } else {
            $definition = preg_replace("/\\(format_version\\)::text = ANY \\(ARRAY\\['donqer-clinical-1'::text, 'donqer-clinical-2'::text\\]\\)/", "(format_version)::text = 'donqer-clinical-1'::text", $definition, -1, $changes);
            if ($changes !== 1) {
                throw new LogicException('Review the report format constraint before restoring it.');
            }
        }
        DB::statement('ALTER TABLE laboratory_report_issuances DROP CONSTRAINT report_values');
        DB::statement('ALTER TABLE laboratory_report_issuances ADD CONSTRAINT report_values '.$definition);
    }

    public function down(): void
    {
        if (DB::table('laboratory_report_issuances')->where('format_version', 'donqer-clinical-2')->exists()) {
            throw new LogicException('Versioned report history requires explicit backup/restoration.');
        }
        DB::unprepared(str_replace('FUNCTION public.report_line_snapshot_v1(', 'FUNCTION public.report_line_snapshot(', DB::scalar("SELECT pg_get_functiondef('report_line_snapshot_v1(bigint)'::regprocedure)")));
        DB::unprepared(str_replace('FUNCTION public.protect_report_issuance_v1(', 'FUNCTION public.protect_report_issuance(', DB::scalar("SELECT pg_get_functiondef('protect_report_issuance_v1()'::regprocedure)")));
        $this->formats(false);
        DB::statement('ALTER TABLE laboratory_report_issuances DROP CONSTRAINT report_pending_shape');
        Schema::table('laboratory_report_issuances', fn (Blueprint $t) => $t->dropColumn('pending_snapshot'));
        DB::unprepared('DROP FUNCTION report_pending_snapshot(bigint); DROP FUNCTION report_line_snapshot_v1(bigint); DROP FUNCTION protect_report_issuance_v1();');
    }
};
