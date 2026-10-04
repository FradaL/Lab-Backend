<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'branches_laboratory_id_id_unique',
            );
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'patients_laboratory_id_id_unique',
            );
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->unique(
                ['laboratory_id', 'id'],
                'doctors_laboratory_id_id_unique',
            );
        });

        Schema::create('laboratory_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id');
            $table->foreignId('branch_id');
            $table->foreignId('patient_id');
            $table->foreignId('doctor_id')->nullable();
            $table->foreignId('commercial_client_id')->nullable();
            $table->foreignId('price_list_id');
            $table->string('code', 45);
            $table->timestamp('ordered_at');
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default('0.00');
            $table->decimal('discount', 12, 2)->default('0.00');
            $table->decimal('taxes', 12, 2)->default('0.00');
            $table->decimal('total', 12, 2)->default('0.00');
            $table->string('currency', 3);
            $table->foreignId('created_by');
            $table->timestamps();

            $table->foreign('laboratory_id', 'laboratory_orders_laboratory_foreign')
                ->references('id')
                ->on('laboratories')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'branch_id'],
                'laboratory_orders_tenant_branch_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'patient_id'],
                'laboratory_orders_tenant_patient_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'doctor_id'],
                'laboratory_orders_tenant_doctor_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('doctors')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'commercial_client_id'],
                'laboratory_orders_tenant_commercial_client_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('commercial_clients')
                ->restrictOnDelete();
            $table->foreign(
                ['laboratory_id', 'price_list_id'],
                'laboratory_orders_tenant_price_list_foreign',
            )
                ->references(['laboratory_id', 'id'])
                ->on('price_lists')
                ->restrictOnDelete();
            $table->foreign('created_by', 'laboratory_orders_created_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->unique(
                ['laboratory_id', 'code'],
                'laboratory_orders_laboratory_code_unique',
            );
            $table->index(
                ['laboratory_id', 'status'],
                'laboratory_orders_laboratory_status_index',
            );
            $table->index(
                ['laboratory_id', 'ordered_at'],
                'laboratory_orders_laboratory_ordered_at_index',
            );
            $table->index(
                ['laboratory_id', 'patient_id', 'ordered_at'],
                'laboratory_orders_laboratory_patient_ordered_at_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE laboratory_orders
                ADD CONSTRAINT laboratory_orders_status_check
                CHECK (status IN ('pending')),
                ADD CONSTRAINT laboratory_orders_subtotal_nonnegative_check
                CHECK (subtotal >= 0),
                ADD CONSTRAINT laboratory_orders_discount_nonnegative_check
                CHECK (discount >= 0),
                ADD CONSTRAINT laboratory_orders_taxes_nonnegative_check
                CHECK (taxes >= 0),
                ADD CONSTRAINT laboratory_orders_total_nonnegative_check
                CHECK (total >= 0),
                ADD CONSTRAINT laboratory_orders_currency_format_check
                CHECK (currency ~ '^[A-Z]{3}$')
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER laboratory_orders_values_insert
                BEFORE INSERT ON laboratory_orders
                WHEN NEW.status != 'pending'
                    OR NEW.subtotal < 0
                    OR NEW.discount < 0
                    OR NEW.taxes < 0
                    OR NEW.total < 0
                    OR length(NEW.currency) != 3
                    OR NEW.currency GLOB '*[^A-Z]*'
                BEGIN
                    SELECT RAISE(ABORT, 'laboratory order values are invalid');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER laboratory_orders_values_update
                BEFORE UPDATE OF status, subtotal, discount, taxes, total, currency ON laboratory_orders
                WHEN NEW.status != 'pending'
                    OR NEW.subtotal < 0
                    OR NEW.discount < 0
                    OR NEW.taxes < 0
                    OR NEW.total < 0
                    OR length(NEW.currency) != 3
                    OR NEW.currency GLOB '*[^A-Z]*'
                BEGIN
                    SELECT RAISE(ABORT, 'laboratory order values are invalid');
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_orders');

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropUnique('doctors_laboratory_id_id_unique');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique('patients_laboratory_id_id_unique');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_laboratory_id_id_unique');
        });
    }
};
