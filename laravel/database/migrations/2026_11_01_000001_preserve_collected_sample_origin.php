<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION preserve_collected_sample_origin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status = 'collected' AND (NEW.status IN ('received', 'accepted') OR NEW.received_at IS NOT NULL OR NEW.receiving_branch_id IS NOT NULL OR NEW.received_by IS NOT NULL)
 THEN RAISE EXCEPTION 'Invalid physical sample transition: collection cannot become reception' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER preserve_collected_sample_origin BEFORE UPDATE ON laboratory_samples FOR EACH ROW EXECUTE FUNCTION preserve_collected_sample_origin();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS preserve_collected_sample_origin ON laboratory_samples; DROP FUNCTION IF EXISTS preserve_collected_sample_origin();');
    }
};
