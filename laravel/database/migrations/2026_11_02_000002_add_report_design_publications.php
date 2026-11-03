<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_report_settings_drafts', function (Blueprint $table) {
            $table->foreignId('laboratory_id')->primary()->constrained('laboratories');
            $table->unsignedBigInteger('revision');
            $table->jsonb('presentation');
        });
        Schema::create('laboratory_report_settings_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained('laboratories');
            $table->unsignedBigInteger('draft_revision');
            $table->jsonb('presentation');
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->string('publisher_name')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->unique(['laboratory_id', 'draft_revision'], 'report_publication_revision_unique');
        });
        // Preserve existing published presentation. No invented historical actor/date.
        DB::statement('INSERT INTO laboratory_report_settings_publications(laboratory_id,draft_revision,presentation) SELECT laboratory_id,revision,presentation FROM laboratory_report_settings');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_report_design_publication() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Published report presentation history is immutable' USING ERRCODE='23514';
END $$;
CREATE TRIGGER protect_report_design_publication BEFORE UPDATE OR DELETE ON laboratory_report_settings_publications FOR EACH ROW EXECUTE FUNCTION protect_report_design_publication();
ALTER TABLE laboratory_report_settings_drafts ADD CHECK(revision>=0 AND jsonb_typeof(presentation)='object');
ALTER TABLE laboratory_report_settings_publications ADD CHECK(draft_revision>=0 AND jsonb_typeof(presentation)='object' AND ((published_by IS NULL AND publisher_name IS NULL AND published_at IS NULL) OR (published_by IS NOT NULL AND publisher_name IS NOT NULL AND published_at IS NOT NULL)));
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_report_settings_drafts');
        Schema::dropIfExists('laboratory_report_settings_publications');
        DB::statement('DROP FUNCTION IF EXISTS protect_report_design_publication()');
    }
};
