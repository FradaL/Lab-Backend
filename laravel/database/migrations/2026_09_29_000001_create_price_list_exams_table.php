<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->foreignId('price_list_id');
            $table->foreignId('laboratory_exam_id');
            $table->decimal('price', 12, 2);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('laboratory_id', 'price_list_exams_laboratory_foreign')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'price_list_id'],
                'price_list_exams_tenant_price_list_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('price_lists')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'laboratory_exam_id'],
                'price_list_exams_tenant_laboratory_exam_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('laboratory_exams')
                ->restrictOnDelete();
            $table->unique(
                ['laboratory_id', 'price_list_id', 'laboratory_exam_id'],
                'price_list_exams_laboratory_list_exam_unique',
            );
            $table->index(
                ['laboratory_id', 'laboratory_exam_id'],
                'price_list_exams_laboratory_exam_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE price_list_exams
                ADD CONSTRAINT price_list_exams_price_nonnegative_check
                CHECK (price >= 0)
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER price_list_exams_price_nonnegative_insert
                BEFORE INSERT ON price_list_exams
                WHEN NEW.price < 0
                BEGIN
                    SELECT RAISE(ABORT, 'price must be nonnegative');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER price_list_exams_price_nonnegative_update
                BEFORE UPDATE OF price ON price_list_exams
                WHEN NEW.price < 0
                BEGIN
                    SELECT RAISE(ABORT, 'price must be nonnegative');
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_exams');
    }
};
