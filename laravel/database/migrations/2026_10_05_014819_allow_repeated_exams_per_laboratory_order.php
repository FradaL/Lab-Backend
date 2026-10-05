<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_order_exams', function (Blueprint $table) {
            $table->dropUnique('laboratory_order_exams_laboratory_order_exam_unique');
            $table->index(
                ['laboratory_id', 'laboratory_order_id'],
                'laboratory_order_exams_laboratory_order_index',
            );
        });
    }

    public function down(): void
    {
        $hasDuplicates = DB::table('laboratory_order_exams')
            ->select(['laboratory_id', 'laboratory_order_id', 'laboratory_exam_id'])
            ->groupBy(['laboratory_id', 'laboratory_order_id', 'laboratory_exam_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Cannot restore the laboratory order exam unique constraint while repeated exams exist.',
            );
        }

        Schema::table('laboratory_order_exams', function (Blueprint $table) {
            $table->dropIndex('laboratory_order_exams_laboratory_order_index');
            $table->unique(
                ['laboratory_id', 'laboratory_order_id', 'laboratory_exam_id'],
                'laboratory_order_exams_laboratory_order_exam_unique',
            );
        });
    }
};
