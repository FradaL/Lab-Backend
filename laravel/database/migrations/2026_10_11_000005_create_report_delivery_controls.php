<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_delivery_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('laboratory_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->string('mode', 40);
            $t->unsignedBigInteger('updated_by');
            $t->timestampTz('updated_at', 6);
            $t->foreign(['laboratory_id', 'updated_by'], 'delivery_policy_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('report_delivery_policy_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedInteger('revision');
            $t->unsignedInteger('previous_revision');
            $t->string('previous_mode', 40);
            $t->string('new_mode', 40);
            $t->string('reason', 1000);
            $t->unsignedBigInteger('changed_by');
            $t->timestampTz('changed_at', 6);
            $t->unique(['laboratory_id', 'revision']);
            $t->foreign('laboratory_id')->references('id')->on('laboratories')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'changed_by'], 'delivery_policy_change_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('report_delivery_exceptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('report_id');
            $t->unsignedInteger('revision');
            $t->string('status', 20);
            $t->string('reason', 1000);
            $t->unsignedInteger('policy_revision');
            $t->string('policy_mode', 40);
            $t->jsonb('evaluation_snapshot');
            $t->unsignedBigInteger('requested_by');
            $t->timestampTz('requested_at', 6);
            $t->unique(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'], 'delivery_exception_identity_unique');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id'], 'delivery_exception_report_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_report_issuances')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'requested_by'], 'delivery_exception_requester_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('report_delivery_exception_authorizations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('report_id');
            $t->unsignedBigInteger('exception_id')->unique();
            $t->unsignedInteger('policy_revision');
            $t->string('policy_mode', 40);
            $t->jsonb('evaluation_snapshot');
            $t->unsignedBigInteger('authorized_by');
            $t->timestampTz('authorized_at', 6);
            $t->unique(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'], 'delivery_authorization_identity_unique');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'exception_id'], 'delivery_authorization_exception_fk')->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])->on('report_delivery_exceptions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'authorized_by'], 'delivery_authorization_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('report_delivery_exception_revocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('report_id');
            $t->unsignedBigInteger('exception_id')->unique();
            $t->unsignedBigInteger('authorization_id')->unique();
            $t->string('reason', 1000);
            $t->unsignedBigInteger('revoked_by');
            $t->timestampTz('revoked_at', 6);
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'exception_id'], 'delivery_revocation_exception_fk')->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])->on('report_delivery_exceptions')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'authorization_id'], 'delivery_revocation_authorization_fk')->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])->on('report_delivery_exception_authorizations')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'revoked_by'], 'delivery_revocation_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('report_delivery_preparations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('report_id');
            $t->string('idempotency_hash', 64);
            $t->string('request_hash', 64);
            $t->string('channel', 30);
            $t->jsonb('recipient_snapshot');
            $t->unsignedInteger('policy_revision');
            $t->string('policy_mode', 40);
            $t->jsonb('evaluation_snapshot');
            $t->unsignedBigInteger('exception_authorization_id')->nullable();
            $t->unsignedBigInteger('prepared_by');
            $t->timestampTz('prepared_at', 6);
            $t->timestampTz('expires_at', 6);
            $t->unsignedBigInteger('downloaded_by')->nullable();
            $t->timestampTz('downloaded_at', 6)->nullable();
            $t->unique(['laboratory_id', 'idempotency_hash'], 'delivery_preparation_idempotency_unique');
            $t->unique(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'], 'delivery_preparation_identity_unique');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id'], 'delivery_preparation_report_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_report_issuances')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'exception_authorization_id'], 'delivery_preparation_authorization_fk')->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])->on('report_delivery_exception_authorizations')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'prepared_by'], 'delivery_preparation_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'downloaded_by'], 'delivery_preparation_downloader_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });
        Schema::create('report_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('laboratory_id');
            $t->unsignedBigInteger('laboratory_order_id');
            $t->unsignedBigInteger('report_id');
            $t->unsignedBigInteger('preparation_id')->unique();
            $t->string('idempotency_hash', 64);
            $t->string('request_hash', 64);
            $t->string('channel', 30);
            $t->jsonb('recipient_snapshot');
            $t->unsignedInteger('policy_revision');
            $t->string('policy_mode', 40);
            $t->jsonb('evaluation_snapshot');
            $t->unsignedBigInteger('exception_authorization_id')->nullable()->unique();
            $t->string('notes', 1000)->nullable();
            $t->unsignedBigInteger('delivered_by');
            $t->timestampTz('delivered_at', 6);
            $t->unique(['laboratory_id', 'idempotency_hash'], 'report_delivery_idempotency_unique');
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id'], 'report_delivery_report_fk')->references(['laboratory_id', 'laboratory_order_id', 'id'])->on('laboratory_report_issuances')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'preparation_id'], 'report_delivery_preparation_fk')->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])->on('report_delivery_preparations')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'laboratory_order_id', 'report_id', 'exception_authorization_id'], 'report_delivery_authorization_fk')->references(['laboratory_id', 'laboratory_order_id', 'report_id', 'id'])->on('report_delivery_exception_authorizations')->restrictOnDelete();
            $t->foreign(['laboratory_id', 'delivered_by'], 'report_delivery_actor_fk')->references(['laboratory_id', 'user_id'])->on('laboratory_user')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE report_delivery_policies ADD CONSTRAINT delivery_policy_values CHECK(revision>0 AND mode IN ('unrestricted','full_payment_required','payment_or_credit'));
ALTER TABLE report_delivery_policy_changes ADD CONSTRAINT delivery_policy_change_values CHECK(revision=previous_revision+1 AND previous_mode IN ('unrestricted','full_payment_required','payment_or_credit') AND new_mode IN ('unrestricted','full_payment_required','payment_or_credit') AND btrim(reason)<>'');
ALTER TABLE report_delivery_exceptions ADD CONSTRAINT delivery_exception_values CHECK(revision>0 AND status IN ('pending','authorized','revoked','consumed') AND btrim(reason)<>'' AND policy_mode IN ('unrestricted','full_payment_required','payment_or_credit') AND jsonb_typeof(evaluation_snapshot)='object');
CREATE UNIQUE INDEX report_delivery_one_active_exception ON report_delivery_exceptions(report_id) WHERE status IN ('pending','authorized');
ALTER TABLE report_delivery_exception_authorizations ADD CONSTRAINT delivery_authorization_values CHECK(policy_mode IN ('full_payment_required','payment_or_credit') AND jsonb_typeof(evaluation_snapshot)='object');
ALTER TABLE report_delivery_exception_revocations ADD CONSTRAINT delivery_revocation_values CHECK(btrim(reason)<>'');
ALTER TABLE report_delivery_preparations ADD CONSTRAINT delivery_preparation_values CHECK(channel IN ('printed_document','manual_digital_file') AND jsonb_typeof(recipient_snapshot)='object' AND policy_mode IN ('unrestricted','full_payment_required','payment_or_credit') AND jsonb_typeof(evaluation_snapshot)='object' AND idempotency_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND expires_at>prepared_at AND expires_at<=prepared_at+interval '30 minutes' AND ((downloaded_by IS NULL)=(downloaded_at IS NULL)));
ALTER TABLE report_deliveries ADD CONSTRAINT report_delivery_values CHECK(channel IN ('printed_document','manual_digital_file') AND jsonb_typeof(recipient_snapshot)='object' AND policy_mode IN ('unrestricted','full_payment_required','payment_or_credit') AND jsonb_typeof(evaluation_snapshot)='object' AND idempotency_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$');

CREATE OR REPLACE FUNCTION report_commercial_satisfied(policy_mode text, evaluation jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
 SELECT policy_mode='unrestricted' OR (evaluation->>'status'='satisfied' AND ((policy_mode='full_payment_required' AND evaluation->>'evidence_type'='full_payment') OR (policy_mode='payment_or_credit' AND evaluation->>'evidence_type' IN ('full_payment','authorized_credit'))));
$$;
CREATE OR REPLACE FUNCTION protect_report_delivery_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='report_delivery_policies' THEN
  IF TG_OP='DELETE' OR NEW.laboratory_id<>OLD.laboratory_id OR NEW.revision<>OLD.revision+1 OR NEW.updated_at<=OLD.updated_at THEN RAISE EXCEPTION 'Delivery policy revision is append-only' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 IF TG_TABLE_NAME='report_delivery_exceptions' AND TG_OP='UPDATE' THEN
  IF to_jsonb(NEW)-'revision'-'status' IS DISTINCT FROM to_jsonb(OLD)-'revision'-'status' OR NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'Delivery exception identity is immutable' USING ERRCODE='23514'; END IF;
  IF OLD.status='pending' AND NEW.status='authorized' AND EXISTS(SELECT 1 FROM report_delivery_exception_authorizations WHERE exception_id=OLD.id) THEN RETURN NEW; END IF;
  IF OLD.status='authorized' AND NEW.status='revoked' AND EXISTS(SELECT 1 FROM report_delivery_exception_revocations WHERE exception_id=OLD.id) AND NOT EXISTS(SELECT 1 FROM report_deliveries WHERE exception_authorization_id=(SELECT id FROM report_delivery_exception_authorizations WHERE exception_id=OLD.id)) THEN RETURN NEW; END IF;
  IF OLD.status='authorized' AND NEW.status='consumed' AND EXISTS(SELECT 1 FROM report_deliveries WHERE exception_authorization_id=(SELECT id FROM report_delivery_exception_authorizations WHERE exception_id=OLD.id)) THEN RETURN NEW; END IF;
  RAISE EXCEPTION 'Invalid delivery exception transition' USING ERRCODE='23514';
 END IF;
 IF TG_TABLE_NAME='report_delivery_preparations' AND TG_OP='UPDATE' THEN
  IF to_jsonb(NEW)-'downloaded_by'-'downloaded_at' IS DISTINCT FROM to_jsonb(OLD)-'downloaded_by'-'downloaded_at' OR OLD.downloaded_at IS NOT NULL OR NEW.downloaded_at IS NULL OR NEW.downloaded_at>OLD.expires_at THEN RAISE EXCEPTION 'Delivery preparation is immutable after one download' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 RAISE EXCEPTION 'Delivery history is immutable' USING ERRCODE='23514';
END $$;
CREATE TRIGGER protect_delivery_policy BEFORE UPDATE OR DELETE ON report_delivery_policies FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();
CREATE TRIGGER protect_delivery_policy_changes BEFORE UPDATE OR DELETE ON report_delivery_policy_changes FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();
CREATE TRIGGER protect_delivery_exception BEFORE UPDATE OR DELETE ON report_delivery_exceptions FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();
CREATE TRIGGER protect_delivery_authorization BEFORE UPDATE OR DELETE ON report_delivery_exception_authorizations FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();
CREATE TRIGGER protect_delivery_revocation BEFORE UPDATE OR DELETE ON report_delivery_exception_revocations FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();
CREATE TRIGGER protect_delivery_preparation BEFORE UPDATE OR DELETE ON report_delivery_preparations FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();
CREATE TRIGGER protect_delivery BEFORE UPDATE OR DELETE ON report_deliveries FOR EACH ROW EXECUTE FUNCTION protect_report_delivery_history();

CREATE OR REPLACE FUNCTION validate_report_delivery_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p report_delivery_preparations%ROWTYPE; current_revision integer; current_mode text;
BEGIN
 SELECT revision,mode INTO current_revision,current_mode FROM report_delivery_policies WHERE laboratory_id=NEW.laboratory_id;
 IF NOT FOUND THEN current_revision:=0; current_mode:='full_payment_required'; END IF;
 IF NEW.policy_revision<>current_revision OR NEW.policy_mode<>current_mode THEN RAISE EXCEPTION 'Delivery requires current policy' USING ERRCODE='23514'; END IF;
 IF NEW.evaluation_snapshot->>'fingerprint' !~ '^[a-f0-9]{64}$' OR NEW.evaluation_snapshot->>'status' NOT IN ('satisfied','not_satisfied','unavailable') THEN RAISE EXCEPTION 'Delivery requires typed commercial evaluation' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='report_delivery_preparations' THEN
  IF report_commercial_satisfied(NEW.policy_mode,NEW.evaluation_snapshot) THEN
   IF NEW.exception_authorization_id IS NOT NULL THEN RAISE EXCEPTION 'Satisfied delivery cannot consume exception' USING ERRCODE='23514'; END IF;
  ELSE
   PERFORM 1 FROM report_delivery_exceptions x JOIN report_delivery_exception_authorizations y ON y.exception_id=x.id WHERE y.id=NEW.exception_authorization_id AND x.laboratory_id=NEW.laboratory_id AND x.laboratory_order_id=NEW.laboratory_order_id AND x.report_id=NEW.report_id AND x.status='authorized' AND y.policy_revision=NEW.policy_revision AND y.policy_mode=NEW.policy_mode AND y.evaluation_snapshot->>'fingerprint'=NEW.evaluation_snapshot->>'fingerprint';
   IF NOT FOUND THEN RAISE EXCEPTION 'Delivery requires exact active commercial authorization' USING ERRCODE='23514'; END IF;
  END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO p FROM report_delivery_preparations WHERE id=NEW.preparation_id FOR UPDATE;
 IF NOT FOUND OR p.laboratory_id<>NEW.laboratory_id OR p.laboratory_order_id<>NEW.laboratory_order_id OR p.report_id<>NEW.report_id OR p.downloaded_at IS NULL OR p.expires_at<=NEW.delivered_at OR p.channel<>NEW.channel OR p.recipient_snapshot IS DISTINCT FROM NEW.recipient_snapshot OR p.policy_revision<>NEW.policy_revision OR p.policy_mode<>NEW.policy_mode OR p.evaluation_snapshot IS DISTINCT FROM NEW.evaluation_snapshot OR p.exception_authorization_id IS DISTINCT FROM NEW.exception_authorization_id THEN RAISE EXCEPTION 'Delivery requires exact downloaded unexpired preparation' USING ERRCODE='23514'; END IF;
 IF NOT report_commercial_satisfied(NEW.policy_mode,NEW.evaluation_snapshot) THEN
  PERFORM 1 FROM report_delivery_exceptions x JOIN report_delivery_exception_authorizations y ON y.exception_id=x.id WHERE y.id=NEW.exception_authorization_id AND x.status='authorized';
  IF NOT FOUND THEN RAISE EXCEPTION 'Delivery exception is unavailable or already consumed' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER validate_delivery_preparation BEFORE INSERT ON report_delivery_preparations FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_write();
CREATE TRIGGER validate_delivery BEFORE INSERT ON report_deliveries FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_write();

CREATE OR REPLACE FUNCTION validate_report_delivery_state_deferred() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE x report_delivery_exceptions%ROWTYPE; policy_revision integer; policy_mode text; authorization_count integer; revocation_count integer; delivery_count integer;
BEGIN
 IF TG_TABLE_NAME='report_delivery_policies' THEN
  IF NOT EXISTS(SELECT 1 FROM report_delivery_policy_changes c WHERE c.laboratory_id=NEW.laboratory_id AND c.revision=NEW.revision AND c.new_mode=NEW.mode) THEN RAISE EXCEPTION 'Delivery policy requires matching immutable history' USING ERRCODE='23514'; END IF;
  RETURN NULL;
 END IF;
 IF TG_TABLE_NAME='report_delivery_policy_changes' THEN
  SELECT revision,mode INTO policy_revision,policy_mode FROM report_delivery_policies WHERE laboratory_id=NEW.laboratory_id;
  IF policy_revision IS NULL OR policy_revision<NEW.revision OR (policy_revision=NEW.revision AND policy_mode<>NEW.new_mode) THEN RAISE EXCEPTION 'Delivery policy history is inconsistent' USING ERRCODE='23514'; END IF;
  RETURN NULL;
 END IF;
 IF TG_TABLE_NAME='report_delivery_exceptions' THEN x:=NEW;
 ELSIF TG_TABLE_NAME='report_delivery_exception_authorizations' THEN SELECT * INTO x FROM report_delivery_exceptions WHERE id=NEW.exception_id;
 ELSIF TG_TABLE_NAME='report_delivery_exception_revocations' THEN SELECT * INTO x FROM report_delivery_exceptions WHERE id=NEW.exception_id;
 ELSIF TG_TABLE_NAME='report_deliveries' AND NEW.exception_authorization_id IS NOT NULL THEN SELECT e.* INTO x FROM report_delivery_exceptions e JOIN report_delivery_exception_authorizations a ON a.exception_id=e.id WHERE a.id=NEW.exception_authorization_id;
 ELSE RETURN NULL;
 END IF;
 IF x.id IS NULL THEN RAISE EXCEPTION 'Commercial exception state is missing' USING ERRCODE='23514'; END IF;
 SELECT count(*) INTO authorization_count FROM report_delivery_exception_authorizations WHERE exception_id=x.id;
 SELECT count(*) INTO revocation_count FROM report_delivery_exception_revocations WHERE exception_id=x.id;
 SELECT count(*) INTO delivery_count FROM report_deliveries d JOIN report_delivery_exception_authorizations a ON a.id=d.exception_authorization_id WHERE a.exception_id=x.id;
 IF (x.status='pending' AND (authorization_count<>0 OR revocation_count<>0 OR delivery_count<>0))
    OR (x.status='authorized' AND (authorization_count<>1 OR revocation_count<>0 OR delivery_count<>0))
    OR (x.status='revoked' AND (authorization_count<>1 OR revocation_count<>1 OR delivery_count<>0))
    OR (x.status='consumed' AND (authorization_count<>1 OR revocation_count<>0 OR delivery_count<>1)) THEN
  RAISE EXCEPTION 'Commercial exception evidence is inconsistent' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER validate_delivery_policy_state AFTER INSERT OR UPDATE ON report_delivery_policies DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_state_deferred();
CREATE CONSTRAINT TRIGGER validate_delivery_policy_history_state AFTER INSERT ON report_delivery_policy_changes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_state_deferred();
CREATE CONSTRAINT TRIGGER validate_delivery_exception_state AFTER INSERT OR UPDATE ON report_delivery_exceptions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_state_deferred();
CREATE CONSTRAINT TRIGGER validate_delivery_authorization_state AFTER INSERT ON report_delivery_exception_authorizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_state_deferred();
CREATE CONSTRAINT TRIGGER validate_delivery_revocation_state AFTER INSERT ON report_delivery_exception_revocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_state_deferred();
CREATE CONSTRAINT TRIGGER validate_delivery_exception_consumption AFTER INSERT ON report_deliveries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_report_delivery_state_deferred();
SQL);
    }

    public function down(): void
    {
        foreach (['report_deliveries', 'report_delivery_preparations', 'report_delivery_exception_authorizations', 'report_delivery_exceptions', 'report_delivery_policy_changes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException('Report delivery history requires an explicit backup/restoration strategy.');
            }
        }
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_delivery_preparations');
        Schema::dropIfExists('report_delivery_exception_revocations');
        Schema::dropIfExists('report_delivery_exception_authorizations');
        Schema::dropIfExists('report_delivery_exceptions');
        Schema::dropIfExists('report_delivery_policy_changes');
        Schema::dropIfExists('report_delivery_policies');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS report_commercial_satisfied(text,jsonb); DROP FUNCTION IF EXISTS protect_report_delivery_history(); DROP FUNCTION IF EXISTS validate_report_delivery_write(); DROP FUNCTION IF EXISTS validate_report_delivery_state_deferred();');
        }
    }
};
