<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->string('name', 75);
            $table->string('description', 255)->nullable();
            $table->string('currency', 3);
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('laboratory_id', 'price_lists_laboratory_foreign')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->unique(
                ['laboratory_id', 'name'],
                'price_lists_laboratory_name_unique',
            );
            $table->index(
                ['laboratory_id', 'status'],
                'price_lists_laboratory_status_index',
            );
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX price_lists_one_default_per_laboratory_unique
            ON price_lists (laboratory_id)
            WHERE is_default = true
            SQL);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE price_lists
                ADD CONSTRAINT price_lists_currency_format_check
                CHECK (currency ~ '^[A-Z]{3}$')
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER price_lists_currency_format_insert
                BEFORE INSERT ON price_lists
                WHEN length(NEW.currency) != 3 OR NEW.currency GLOB '*[^A-Z]*'
                BEGIN
                    SELECT RAISE(ABORT, 'currency must contain exactly three uppercase ASCII letters');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER price_lists_currency_format_update
                BEFORE UPDATE OF currency ON price_lists
                WHEN length(NEW.currency) != 3 OR NEW.currency GLOB '*[^A-Z]*'
                BEGIN
                    SELECT RAISE(ABORT, 'currency must contain exactly three uppercase ASCII letters');
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
