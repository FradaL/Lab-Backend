<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'laboratory_orders_laboratory_id_id_unique',
            );
        });

        Schema::create('laboratory_order_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->foreignId('laboratory_order_id');
            $table->foreignId('laboratory_exam_id');
            $table->foreignId('price_list_id');
            $table->decimal('unit_price', 12, 2);
            $table->string('exam_code', 30);
            $table->string('exam_name', 150);
            $table->string('price_list_name', 75);
            $table->timestamps();

            $table->foreign('laboratory_id', 'laboratory_order_exams_laboratory_foreign')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'laboratory_order_id'],
                'laboratory_order_exams_tenant_order_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('laboratory_orders')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'laboratory_exam_id'],
                'laboratory_order_exams_tenant_exam_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('laboratory_exams')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'price_list_id'],
                'laboratory_order_exams_tenant_price_list_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('price_lists')
                ->restrictOnDelete();
            $table->unique(
                ['laboratory_id', 'laboratory_order_id', 'laboratory_exam_id'],
                'laboratory_order_exams_laboratory_order_exam_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_order_exams
                ADD CONSTRAINT laboratory_order_exams_unit_price_nonnegative_check
                CHECK (unit_price >= 0)
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER laboratory_order_exams_unit_price_nonnegative_insert
                BEFORE INSERT ON laboratory_order_exams
                WHEN NEW.unit_price < 0
                BEGIN
                    SELECT RAISE(ABORT, 'unit_price must be nonnegative');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER laboratory_order_exams_unit_price_nonnegative_update
                BEFORE UPDATE OF unit_price ON laboratory_order_exams
                WHEN NEW.unit_price < 0
                BEGIN
                    SELECT RAISE(ABORT, 'unit_price must be nonnegative');
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_order_exams');

        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropUnique('laboratory_orders_laboratory_id_id_unique');
        });
    }
};
