<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laboratory_user', function (Blueprint $table) {
            $table->boolean('is_default')->default(false);
        });

        DB::statement(
            'CREATE UNIQUE INDEX laboratory_user_one_default_per_user '
            .'ON laboratory_user (user_id) WHERE is_default = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS laboratory_user_one_default_per_user');

        Schema::table('laboratory_user', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
