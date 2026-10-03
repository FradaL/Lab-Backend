<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->string('name', 150);
            $table->string('type', 20);
            $table->string('tax_id', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('laboratory_id', 'commercial_clients_laboratory_foreign')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->unique(
                ['laboratory_id', 'name'],
                'commercial_clients_laboratory_name_unique',
            );
            $table->index(
                ['laboratory_id', 'status'],
                'commercial_clients_laboratory_status_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE commercial_clients
                ADD CONSTRAINT commercial_clients_type_check
                CHECK (type IN ('insurance', 'company', 'agreement', 'other'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE commercial_clients
                ADD CONSTRAINT commercial_clients_status_check
                CHECK (status IN ('active', 'inactive'))
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER commercial_clients_values_insert
                BEFORE INSERT ON commercial_clients
                WHEN NEW.type NOT IN ('insurance', 'company', 'agreement', 'other')
                    OR NEW.status NOT IN ('active', 'inactive')
                BEGIN
                    SELECT RAISE(ABORT, 'commercial client type or status is invalid');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER commercial_clients_values_update
                BEFORE UPDATE OF type, status ON commercial_clients
                WHEN NEW.type NOT IN ('insurance', 'company', 'agreement', 'other')
                    OR NEW.status NOT IN ('active', 'inactive')
                BEGIN
                    SELECT RAISE(ABORT, 'commercial client type or status is invalid');
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_clients');
    }
};
