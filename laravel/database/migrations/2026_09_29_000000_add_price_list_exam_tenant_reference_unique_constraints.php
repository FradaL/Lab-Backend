<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'price_lists_laboratory_id_id_unique',
            );
        });

        Schema::table('laboratory_exams', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'laboratory_exams_laboratory_id_id_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_exams', function (Blueprint $table) {
            $table->dropUnique('laboratory_exams_laboratory_id_id_unique');
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropUnique('price_lists_laboratory_id_id_unique');
        });
    }
};
