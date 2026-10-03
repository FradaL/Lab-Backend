<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_clients', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'commercial_clients_laboratory_id_id_unique',
            );
        });

        Schema::create('commercial_client_price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->foreignId('commercial_client_id');
            $table->foreignId('price_list_id');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('laboratory_id', 'ccpl_laboratory_foreign')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'commercial_client_id'],
                'ccpl_tenant_commercial_client_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('commercial_clients')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'price_list_id'],
                'ccpl_tenant_price_list_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('price_lists')
                ->restrictOnDelete();
            $table->unique(
                ['laboratory_id', 'commercial_client_id', 'price_list_id', 'starts_at'],
                'ccpl_laboratory_client_list_starts_unique',
            );
            $table->index(
                ['laboratory_id', 'commercial_client_id', 'status', 'starts_at'],
                'ccpl_laboratory_client_status_starts_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE commercial_client_price_lists
                ADD CONSTRAINT ccpl_date_range_check
                CHECK (ends_at IS NULL OR ends_at >= starts_at)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE commercial_client_price_lists
                ADD CONSTRAINT ccpl_status_check
                CHECK (status IN ('active', 'inactive'))
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER ccpl_values_insert
                BEFORE INSERT ON commercial_client_price_lists
                WHEN (NEW.ends_at IS NOT NULL AND NEW.ends_at < NEW.starts_at)
                    OR NEW.status NOT IN ('active', 'inactive')
                BEGIN
                    SELECT RAISE(ABORT, 'commercial client price list dates or status are invalid');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER ccpl_values_update
                BEFORE UPDATE OF starts_at, ends_at, status ON commercial_client_price_lists
                WHEN (NEW.ends_at IS NOT NULL AND NEW.ends_at < NEW.starts_at)
                    OR NEW.status NOT IN ('active', 'inactive')
                BEGIN
                    SELECT RAISE(ABORT, 'commercial client price list dates or status are invalid');
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_client_price_lists');

        Schema::table('commercial_clients', function (Blueprint $table) {
            $table->dropUnique('commercial_clients_laboratory_id_id_unique');
        });
    }
};
