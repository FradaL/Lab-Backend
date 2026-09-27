<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_areas', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'laboratory_areas_laboratory_id_id_unique',
            );
        });

        Schema::table('sample_types', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'sample_types_laboratory_id_id_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sample_types', function (Blueprint $table) {
            $table->dropUnique('sample_types_laboratory_id_id_unique');
        });

        Schema::table('laboratory_areas', function (Blueprint $table) {
            $table->dropUnique('laboratory_areas_laboratory_id_id_unique');
        });
    }
};
