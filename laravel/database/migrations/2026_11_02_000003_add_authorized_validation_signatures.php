<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_signature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained('laboratories');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('professional_id')->constrained('validation_professionals');
            $table->string('action', 10);
            $table->text('image')->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->timestampTz('recorded_at');
            $table->index(['laboratory_id', 'user_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_validation_signature_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Signature authorization history is immutable' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM validation_professionals WHERE id=NEW.professional_id AND laboratory_id=NEW.laboratory_id AND user_id=NEW.user_id)
 OR NEW.action NOT IN ('grant','revoke') OR (NEW.action='grant' AND (NEW.image IS NULL OR NEW.image_hash IS NULL)) OR (NEW.action='revoke' AND (NEW.image IS NOT NULL OR NEW.image_hash IS NOT NULL)) THEN
  RAISE EXCEPTION 'Signature must belong to the authenticated professional identity' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_validation_signature_event BEFORE INSERT OR UPDATE OR DELETE ON validation_signature_events FOR EACH ROW EXECUTE FUNCTION protect_validation_signature_event();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_signature_events');
        DB::statement('DROP FUNCTION IF EXISTS protect_validation_signature_event()');
    }
};
