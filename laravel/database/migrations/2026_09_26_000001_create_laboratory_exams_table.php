<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->foreignId('laboratory_area_id');
            $table->foreignId('sample_type_id');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->integer('turnaround_time_minutes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign(
                'laboratory_id',
                'laboratory_exams_laboratory_foreign',
            )
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();

            $table->foreign(
                ['laboratory_id', 'laboratory_area_id'],
                'laboratory_exams_tenant_area_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('laboratory_areas')
                ->restrictOnDelete();

            $table->foreign(
                ['laboratory_id', 'sample_type_id'],
                'laboratory_exams_tenant_sample_type_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('sample_types')
                ->restrictOnDelete();

            $table->unique(
                ['laboratory_id', 'code'],
                'laboratory_exams_laboratory_code_unique',
            );
            $table->index(
                ['laboratory_id', 'status'],
                'laboratory_exams_laboratory_status_index',
            );
            $table->index(
                ['laboratory_id', 'laboratory_area_id'],
                'laboratory_exams_laboratory_area_index',
            );
            $table->index(
                ['laboratory_id', 'sample_type_id'],
                'laboratory_exams_laboratory_sample_type_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_exams
                ADD CONSTRAINT laboratory_exams_turnaround_nonnegative_check
                CHECK (turnaround_time_minutes IS NULL OR turnaround_time_minutes >= 0)
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER laboratory_exams_turnaround_nonnegative_insert
                BEFORE INSERT ON laboratory_exams
                WHEN NEW.turnaround_time_minutes < 0
                BEGIN
                    SELECT RAISE(ABORT, 'turnaround_time_minutes must be nonnegative');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER laboratory_exams_turnaround_nonnegative_update
                BEFORE UPDATE OF turnaround_time_minutes ON laboratory_exams
                WHEN NEW.turnaround_time_minutes < 0
                BEGIN
                    SELECT RAISE(ABORT, 'turnaround_time_minutes must be nonnegative');
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_exams');
    }
};
