<?php

namespace Tests\Feature\ReportDelivery;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalReports\ClinicalReportService;
use App\Models\Laboratory;
use App\Models\ReportDeliveryPreparation;
use App\Models\Subscription;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReportDeliveryExceptionTest extends TestCase
{
    use DatabaseMigrations, ReportDeliveryScenario {
        ReportDeliveryScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function test_authorization_clock_is_fixed_and_binding_is_exclusive(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $this->travelTo(now()->startOfSecond()->addMicroseconds(123456));
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $deadline = now()->addMinutes(15);
        $this->assertSame($deadline->toISOString(), $authorized['authorization']['expires_at']);
        $this->assertNull($authorized['authorization']['preparation_id']);
        $this->travel(10)->minutes();

        $service = app(ReportDeliveryService::class);
        $input = $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-first-preparation');

        $this->assertTrue($deadline->equalTo($preparation->fresh()->expires_at));
        $this->assertSame($preparation->id, $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-first-preparation')->id);
        $this->assertConflict('report_delivery_idempotency_conflict', fn () => $service->prepare(
            $lab, $actor, $order->id, $report->id, array_replace($input, ['channel' => 'manual_digital_file']), 'exception-first-preparation'
        ));
        $this->assertConflict('report_delivery_exception_bound', fn () => $service->prepare(
            $lab, $actor, $order->id, $report->id, $input, 'exception-second-preparation'
        ));
        $this->assertDatabaseCount('report_delivery_preparations', 1);
        $this->assertDatabaseCount('report_delivery_exception_bindings', 1);
        $history = $service->exceptions($lab, $actor, $order->id, $report->id);
        $this->assertSame($preparation->id, $history['data'][0]['authorization']['preparation_id']);
        $this->assertSame($deadline->toISOString(), $history['data'][0]['authorization']['expires_at']);
    }

    #[DataProvider('expirationBoundaries')]
    public function test_expiration_is_enforced_at_the_exact_boundary(int $seconds, bool $valid): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $this->travelTo(now()->startOfSecond());
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $this->travel($seconds)->seconds();
        $service = app(ReportDeliveryService::class);
        $eligible = $service->eligibility($lab, $actor, $order->id, $report->id);
        $this->assertSame($valid, $eligible['eligible']);
        $operation = fn () => $service->prepare($lab, $actor, $order->id, $report->id,
            $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'exception-boundary-preparation');

        if ($valid) {
            $this->assertInstanceOf(ReportDeliveryPreparation::class, $operation());
        } else {
            $this->assertConflict('report_delivery_exception_expired', $operation);
            $this->assertDatabaseCount('report_delivery_preparations', 0);
        }
    }

    public static function expirationBoundaries(): array
    {
        return ['before' => [899, true], 'exactly' => [900, false], 'after' => [901, false]];
    }

    public function test_expired_preparation_cannot_be_recreated_downloaded_or_confirmed(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $this->travelTo(now()->startOfSecond());
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $service = app(ReportDeliveryService::class);
        $input = $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-expiration-prep');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        $this->travel(15)->minutes();

        $this->assertConflict('report_delivery_exception_expired', fn () => $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-recreated-prep'));
        $this->assertConflict('report_delivery_preparation_expired', fn () => $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id));
        $this->assertConflict('report_delivery_preparation_expired', fn () => $service->deliver($lab, $actor, $order->id, $report->id,
            ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'exception-expired-delivery'));
        // Un reintento de preparación no confirmada se revalida y no renueva el plazo.
        $this->assertConflict('report_delivery_preparation_expired', fn () => $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-expiration-prep'));
        $this->assertDatabaseCount('report_deliveries', 0);
    }

    #[DataProvider('revocationStages')]
    public function test_revocation_blocks_bound_preparation_and_preserves_history(bool $downloadFirst): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $service = app(ReportDeliveryService::class);
        $input = $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-revocation-prep');
        if ($downloadFirst) {
            $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        }
        $service->revokeException($lab, $actor, $order->id, $report->id, $authorized['exception']['id'],
            ['revision' => 2, 'reason' => 'FICTICIO: autorización retirada.']);

        if ($downloadFirst) {
            $this->assertConflict('report_delivery_exception_unavailable', fn () => $service->deliver($lab, $actor, $order->id, $report->id,
                ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'exception-revoked-delivery'));
        } else {
            $this->assertConflict('report_delivery_exception_unavailable', fn () => $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id));
        }
        $this->assertConflict('report_delivery_exception_unavailable', fn () => $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-revoked-new-prep'));
        $this->assertFalse($service->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        $this->assertDatabaseCount('report_delivery_exception_bindings', 1);
        $this->assertDatabaseCount('report_delivery_exception_revocations', 1);
        $this->assertDatabaseCount('report_deliveries', 0);
    }

    public static function revocationStages(): array
    {
        return [['downloadFirst' => false], ['downloadFirst' => true]];
    }

    public function test_financial_change_invalidates_exception_without_treating_it_as_payment(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $this->recordPayment($lab, $actor, $order, '0.01', 'exception-financial-change');
        $service = app(ReportDeliveryService::class);

        $this->assertFalse($service->eligibility($lab, $actor, $order->id, $report->id)['eligible']);
        $this->assertConflict('report_delivery_exception_unavailable', fn () => $service->prepare($lab, $actor, $order->id, $report->id,
            $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'exception-changed-finances'));
        $this->assertDatabaseCount('report_delivery_preparations', 0);
    }

    #[DataProvider('sensitiveOperations')]
    public function test_service_and_sql_reject_expired_download_or_confirmation(string $operation): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $this->travelTo(now()->startOfSecond());
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $service = app(ReportDeliveryService::class);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id,
            $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'exception-sensitive-prep');
        if ($operation === 'confirmation') {
            $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        }
        $this->travel(15)->minutes();

        if ($operation === 'download') {
            $this->assertConflict('report_delivery_preparation_expired', fn () => $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id));
            $this->assertSqlRejected(fn () => DB::table('report_delivery_preparations')->where('id', $preparation->id)
                ->update(['downloaded_by' => $actor->id, 'downloaded_at' => now()]), '23514');
            $this->assertNull($preparation->fresh()->downloaded_at);
        } else {
            $this->assertConflict('report_delivery_preparation_expired', fn () => $service->deliver($lab, $actor, $order->id, $report->id,
                ['preparation_id' => $preparation->id, 'delivery_confirmed' => true], 'exception-sensitive-delivery'));
            $this->assertSqlRejected(fn () => DB::table('report_deliveries')->insert([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'report_id' => $report->id,
                'preparation_id' => $preparation->id, 'idempotency_hash' => hash('sha256', 'FICTICIO: vencida'),
                'request_hash' => hash('sha256', 'FICTICIO: solicitud'), 'channel' => $preparation->channel,
                'recipient_snapshot' => json_encode($preparation->recipient_snapshot), 'policy_revision' => $preparation->policy_revision,
                'policy_mode' => $preparation->policy_mode, 'evaluation_snapshot' => json_encode($preparation->evaluation_snapshot),
                'exception_authorization_id' => $authorized['authorization']['id'], 'delivered_by' => $actor->id, 'delivered_at' => now(),
            ]), '23514');
            $this->assertDatabaseCount('report_deliveries', 0);
        }
    }

    public static function sensitiveOperations(): array
    {
        return [['download'], ['confirmation']];
    }

    public function test_same_employee_can_authorize_and_deliver_once_without_financial_or_clinical_mutation(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $before = $this->protectedEvidence($order, $report);
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $service = app(ReportDeliveryService::class);
        $input = $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-single-use-prep');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        $body = ['preparation_id' => $preparation->id, 'delivery_confirmed' => true];
        $delivery = $service->deliver($lab, $actor, $order->id, $report->id, $body, 'exception-single-use-delivery');
        $this->travel(16)->minutes();

        $this->assertSame($delivery->id, $service->deliver($lab, $actor, $order->id, $report->id, $body, 'exception-single-use-delivery')->id);
        $this->assertSame($actor->id, $delivery->delivered_by);
        $this->assertSame($before, $this->protectedEvidence($order, $report));
        $this->assertDatabaseCount('report_deliveries', 1);
        $this->assertDatabaseHas('report_delivery_exceptions', ['id' => $authorized['exception']['id'], 'status' => 'consumed']);
        $this->assertConflict('report_delivery_exception_unavailable', fn () => $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-consumed-new-prep'));
    }

    public function test_authorization_cannot_be_moved_to_another_issuance_or_laboratory(): void
    {
        [$lab, $actor, $order, $line, $report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $otherReport = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'exception-other-exact-report');
        $this->releaseReport($lab, $actor, $order, $otherReport);
        $url = "/api/v1/laboratory-orders/$order->id/reports/$otherReport->id/delivery-preparations";
        $input = $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]);
        $this->withHeader('Idempotency-Key', 'exception-wrong-report-key')->postJson($url, $input)->assertNotFound();

        $foreign = Laboratory::factory()->create();
        Subscription::factory()->for($foreign)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $foreignActor = $this->createLaboratoryMember($foreign);
        $this->assignDirectLaboratoryPermission($foreignActor, $foreign, 'reports.delivery.prepare');
        setPermissionsTeamId($foreign->id);
        $this->actingAs($foreignActor)->withHeader('X-Laboratory-ID', (string) $foreign->id)
            ->withHeader('Idempotency-Key', 'exception-wrong-lab-key')->postJson($url, $input)->assertNotFound();
        $this->assertDatabaseCount('report_delivery_preparations', 0);
    }

    public function test_authorization_permission_does_not_grant_preparation_or_delivery(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $service = app(ReportDeliveryService::class);
        $exception = $service->requestException($lab, $actor, $order->id, $report->id, ['reason' => 'FICTICIO: permisos independientes.']);
        $authorizer = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($authorizer, $lab, 'reports.delivery_exceptions.authorize');
        $authorized = $service->authorizeException($lab, $authorizer, $order->id, $report->id, $exception['id'], ['revision' => 1]);
        $input = $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]);
        $preparation = $service->prepare($lab, $actor, $order->id, $report->id, $input, 'exception-permissions-prep');
        $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparation->id);
        $base = "/api/v1/laboratory-orders/$order->id/reports/$report->id";
        $this->actingAs($authorizer)->withHeader('Idempotency-Key', 'exception-no-prepare-key')->postJson("$base/delivery-preparations", $input)->assertForbidden();
        $this->getJson("$base/delivery-preparations/$preparation->id/download")->assertForbidden();
        $this->withHeader('Idempotency-Key', 'exception-no-delivery-key')->postJson("$base/deliveries",
            ['preparation_id' => $preparation->id, 'delivery_confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('report_deliveries', 0);
    }

    #[DataProvider('auditFailures')]
    public function test_audit_failure_rolls_back_authorization_or_exclusive_binding(string $event): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $service = app(ReportDeliveryService::class);
        $exception = $service->requestException($lab, $actor, $order->id, $report->id, ['reason' => 'FICTICIO: fallo de auditoría.']);
        $authorized = $event === 'order_report.delivery_prepared'
            ? $service->authorizeException($lab, $actor, $order->id, $report->id, $exception['id'], ['revision' => 1]) : null;
        DB::unprepared("CREATE FUNCTION fail_be09_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.event='$event' THEN RAISE EXCEPTION 'FICTICIO: fallo de auditoría'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER fail_be09_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_be09_audit();");
        try {
            if ($authorized === null) {
                $service->authorizeException($lab, $actor, $order->id, $report->id, $exception['id'], ['revision' => 1]);
            } else {
                $service->prepare($lab, $actor, $order->id, $report->id,
                    $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'exception-audit-rollback-prep');
            }
            $this->fail('La operación debe revertirse si falla su auditoría.');
        } catch (QueryException) {
            $this->assertDatabaseCount('report_delivery_exception_authorizations', $authorized === null ? 0 : 1);
            $this->assertDatabaseCount('report_delivery_preparations', 0);
            $this->assertDatabaseCount('report_delivery_exception_bindings', 0);
            $this->assertDatabaseHas('report_delivery_exceptions', ['id' => $exception['id'], 'status' => $authorized === null ? 'pending' : 'authorized']);
        } finally {
            DB::unprepared('DROP TRIGGER fail_be09_audit ON audit_logs; DROP FUNCTION fail_be09_audit();');
        }
    }

    public static function auditFailures(): array
    {
        return [['order_report.delivery_exception_authorized'], ['order_report.delivery_prepared']];
    }

    #[DataProvider('invalidSqlPreparations')]
    public function test_postgresql_rejects_invalid_preparation_even_without_the_service(string $case): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $preparation = app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id,
            $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'exception-sql-original-prep');
        $row = (array) DB::table('report_delivery_preparations')->where('id', $preparation->id)->first();
        unset($row['id']);
        $row['idempotency_hash'] = hash('sha256', 'FICTICIO: sql distinto');
        if ($case === 'over_15_minutes') {
            $row['expires_at'] = $preparation->prepared_at->addMinutes(16);
        } elseif ($case === 'after_authorization_expiry') {
            $row['prepared_at'] = $preparation->expires_at;
            $row['expires_at'] = $preparation->expires_at->addMinute();
        }
        $this->assertSqlRejected(fn () => DB::transaction(function () use ($row, $case) {
            $id = DB::table('report_delivery_preparations')->insertGetId($row);
            if ($case === 'duplicate_binding') {
                DB::table('report_delivery_exception_bindings')->insert([
                    'laboratory_id' => $row['laboratory_id'], 'laboratory_order_id' => $row['laboratory_order_id'],
                    'report_id' => $row['report_id'], 'authorization_id' => $row['exception_authorization_id'], 'preparation_id' => $id,
                ]);
            }
        }), in_array($case, ['missing_binding', 'duplicate_binding'], true) ? '23505' : '23514');
        $this->assertDatabaseCount('report_delivery_preparations', 1);
        $this->assertDatabaseCount('report_delivery_exception_bindings', 1);
    }

    public static function invalidSqlPreparations(): array
    {
        return [['missing_binding'], ['duplicate_binding'], ['over_15_minutes'], ['after_authorization_expiry']];
    }

    public function test_postgresql_protects_binding_and_authorization_deadline_from_changes(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id,
            $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'exception-immutable-prep');
        $this->assertSqlRejected(fn () => DB::table('report_delivery_exception_bindings')->delete(), '23514');
        $this->assertSqlRejected(fn () => DB::table('report_delivery_exception_bindings')->update(['report_id' => $report->id]), '23514');
        $this->assertSqlRejected(fn () => DB::table('report_delivery_exception_authorizations')
            ->where('id', $authorized['authorization']['id'])->update(['expires_at' => now()->addHour()]), '23514');
    }

    public function test_postgresql_uses_its_own_clock_to_reject_backdated_expired_exceptions(): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        // Simula fechas retroactivas: PostgreSQL conserva su reloj real.
        $this->travel(-16)->minutes();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $this->assertSqlRejected(fn () => app(ReportDeliveryService::class)->prepare($lab, $actor, $order->id, $report->id,
            $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), 'exception-backdated-prep'), '23514');
        $this->assertDatabaseCount('report_delivery_preparations', 0);
        $this->assertDatabaseCount('report_delivery_exception_bindings', 0);
    }

    #[DataProvider('invalidAuthorizationLifetimes')]
    public function test_postgresql_requires_a_deadline_no_later_than_15_minutes(bool $missing): void
    {
        [$lab, $actor, $order, , $report] = $this->issued();
        $authorized = $this->exceptionAuthorization($lab, $actor, $order, $report);
        $row = (array) DB::table('report_delivery_exception_authorizations')->where('id', $authorized['authorization']['id'])->first();
        unset($row['id']);
        $row['expires_at'] = $missing ? null : now()->addMinutes(16)->format('Y-m-d H:i:s.u');
        $this->assertSqlRejected(fn () => DB::table('report_delivery_exception_authorizations')->insert($row), '23514');
        $this->assertDatabaseCount('report_delivery_exception_authorizations', 1);
    }

    public static function invalidAuthorizationLifetimes(): array
    {
        return [[true], [false]];
    }

    private function protectedEvidence($order, $report): array
    {
        return [
            $order->fresh()->getAttributes(), $report->fresh()->getAttributes(),
            DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->get()->toJson(),
            DB::table('laboratory_order_financial_revisions')->where('laboratory_order_id', $order->id)->orderBy('revision')->get()->toJson(),
            DB::table('laboratory_order_cash_movements')->where('laboratory_order_id', $order->id)->get()->toJson(),
        ];
    }

    private function assertConflict(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Se esperaba el conflicto $code.");
        } catch (ClinicalConflict $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    private function assertSqlRejected(callable $operation, string $state): void
    {
        try {
            $operation();
            $this->fail('PostgreSQL debe rechazar la escritura.');
        } catch (\PDOException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
