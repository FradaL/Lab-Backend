<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_delivery_exception_authorizations', function (Blueprint $table) {
            // Null identifica historia sin plazo verificable; no se inventa una vigencia.
            $table->timestampTz('expires_at', 6)->nullable();
        });

        Schema::create('report_delivery_exception_bindings', function (Blueprint $table) {
            $table->unsignedBigInteger('laboratory_id');
            $table->unsignedBigInteger('laboratory_order_id');
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('authorization_id')->primary();
            $table->unsignedBigInteger('preparation_id')->unique();
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'authorization_id'], 'delivery_binding_authorization_fk')
                ->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])
                ->on('report_delivery_exception_authorizations')->restrictOnDelete();
            $table->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'preparation_id'], 'delivery_binding_preparation_fk')
                ->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])
                ->on('report_delivery_preparations')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE report_delivery_exception_authorizations
    ADD CONSTRAINT delivery_authorization_lifetime CHECK (
        expires_at IS NULL OR (expires_at > authorized_at AND expires_at <= authorized_at + interval '15 minutes')
    );

CREATE OR REPLACE FUNCTION validate_delivery_exception_lifetime() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    exception_authorization report_delivery_exception_authorizations%ROWTYPE;
    preparation report_delivery_preparations%ROWTYPE;
    event_at timestamptz;
BEGIN
    IF TG_TABLE_NAME = 'report_delivery_exception_authorizations' THEN
        IF NEW.expires_at IS NULL THEN
            RAISE EXCEPTION 'New commercial authorizations require a fixed expiration' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF TG_TABLE_NAME = 'report_delivery_preparations' THEN
        IF NEW.expires_at > NEW.prepared_at + interval '15 minutes' THEN
            RAISE EXCEPTION 'Delivery preparation exceeds 15 minutes' USING ERRCODE = '23514';
        END IF;
        IF TG_OP = 'UPDATE' THEN
            event_at := NEW.downloaded_at;
        ELSE
            event_at := NEW.prepared_at;
        END IF;
        IF event_at >= NEW.expires_at THEN
            RAISE EXCEPTION 'Delivery preparation has expired' USING ERRCODE = '23514';
        END IF;
        IF NEW.exception_authorization_id IS NULL THEN
            RETURN NEW;
        END IF;
        SELECT * INTO exception_authorization FROM report_delivery_exception_authorizations
            WHERE id = NEW.exception_authorization_id FOR UPDATE;
        IF NOT FOUND OR exception_authorization.expires_at IS NULL
            OR exception_authorization.expires_at <= clock_timestamp()
            OR NEW.prepared_at < exception_authorization.authorized_at
            OR NEW.expires_at > exception_authorization.expires_at
            OR event_at < NEW.prepared_at OR event_at >= exception_authorization.expires_at
            OR NOT EXISTS (SELECT 1 FROM report_delivery_exceptions
                WHERE id = exception_authorization.exception_id AND status = 'authorized') THEN
            RAISE EXCEPTION 'Commercial authorization is expired or unavailable' USING ERRCODE = '23514';
        END IF;
        IF TG_OP = 'UPDATE' AND NOT EXISTS (
            SELECT 1 FROM report_delivery_exception_bindings
            WHERE authorization_id = exception_authorization.id AND preparation_id = NEW.id
        ) THEN
            RAISE EXCEPTION 'Download requires the exclusive exception binding' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    SELECT * INTO preparation FROM report_delivery_preparations WHERE id = NEW.preparation_id FOR UPDATE;
    IF NEW.exception_authorization_id IS NOT NULL THEN
        SELECT * INTO exception_authorization FROM report_delivery_exception_authorizations
            WHERE id = NEW.exception_authorization_id FOR UPDATE;
        IF NOT FOUND OR exception_authorization.expires_at IS NULL
            OR exception_authorization.expires_at <= clock_timestamp()
            OR NEW.delivered_at < preparation.prepared_at OR NEW.delivered_at >= exception_authorization.expires_at
            OR NOT EXISTS (SELECT 1 FROM report_delivery_exception_bindings
                WHERE authorization_id = exception_authorization.id AND preparation_id = preparation.id) THEN
            RAISE EXCEPTION 'Delivery requires the exact unexpired exception binding' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER validate_delivery_authorization_lifetime
    BEFORE INSERT ON report_delivery_exception_authorizations
    FOR EACH ROW EXECUTE FUNCTION validate_delivery_exception_lifetime();
CREATE TRIGGER validate_delivery_preparation_lifetime
    BEFORE INSERT OR UPDATE ON report_delivery_preparations
    FOR EACH ROW EXECUTE FUNCTION validate_delivery_exception_lifetime();
CREATE TRIGGER validate_delivery_exception_lifetime
    BEFORE INSERT ON report_deliveries
    FOR EACH ROW EXECUTE FUNCTION validate_delivery_exception_lifetime();

CREATE OR REPLACE FUNCTION validate_delivery_exception_binding() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    preparation report_delivery_preparations%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'report_delivery_exception_bindings' THEN
        SELECT * INTO preparation FROM report_delivery_preparations WHERE id = NEW.preparation_id;
        IF preparation.exception_authorization_id IS DISTINCT FROM NEW.authorization_id THEN
            RAISE EXCEPTION 'Exception binding must match its preparation' USING ERRCODE = '23514';
        END IF;
    ELSE
        preparation := NEW;
    END IF;
    IF preparation.exception_authorization_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM report_delivery_exception_bindings
        WHERE authorization_id = preparation.exception_authorization_id AND preparation_id = preparation.id
    ) THEN
        RAISE EXCEPTION 'Exceptional preparation requires an exclusive binding' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END $$;

CREATE TRIGGER protect_delivery_exception_binding
    BEFORE UPDATE OR DELETE ON report_delivery_exception_bindings
    FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();
CREATE CONSTRAINT TRIGGER validate_delivery_binding
    AFTER INSERT ON report_delivery_exception_bindings DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION validate_delivery_exception_binding();
CREATE CONSTRAINT TRIGGER validate_preparation_exception_binding
    AFTER INSERT ON report_delivery_preparations DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION validate_delivery_exception_binding();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('La vigencia y los vínculos históricos de entrega requieren una estrategia explícita de restauración.');
    }
};
