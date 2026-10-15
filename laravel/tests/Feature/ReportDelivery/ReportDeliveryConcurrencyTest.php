<?php

namespace Tests\Feature\ReportDelivery;

use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\ReportDelivery\CommercialAssessment;
use App\ReportDelivery\CommercialComplianceProvider;
use App\ReportDelivery\ReportDeliveryPolicyService;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

final class ReportDeliveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations, ReportDeliveryScenario {
        ReportDeliveryScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    #[DataProvider('deliveryRaces')]
    public function test_delivery_races_use_independent_postgresql_connections(string $race, string $expected): void
    {
        [$lab,$actor,$order,$line,$report] = $this->issued();
        $service = app(ReportDeliveryService::class);
        $preparations = [];
        $exception = null;
        if ($race === 'commercial_evidence_change') {
            $order->update(['notes' => 'evidence-v1']);
            $this->databaseBackedProvider();
            $service = app(ReportDeliveryService::class);
        } elseif (in_array($race, ['exception_reuse', 'two_authorizations'], true)) {
            $exception = $service->requestException($lab, $actor, $order->id, $report->id, ['reason' => 'FICTICIO: carrera autorizada.']);
            if ($race === 'exception_reuse') {
                $authorized = $service->authorizeException($lab, $actor, $order->id, $report->id, $exception['id'], ['revision' => 1]);
                foreach ([1, 2] as $index) {
                    $preparations[$index] = $service->prepare($lab, $actor, $order->id, $report->id,
                        $this->recipient(extra: ['exception_authorization_id' => $authorized['authorization']['id']]), "race-exception-prep-$index");
                    $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparations[$index]->id);
                }
            }
        } else {
            app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'unrestricted', 'reason' => 'FICTICIO: carrera.']);
        }
        if (in_array($race, ['same_idempotency_key', 'same_preparation', 'policy_change', 'commercial_evidence_change'], true)) {
            $preparations[1] = $service->prepare($lab, $actor, $order->id, $report->id, $this->recipient(), 'race-standard-preparation');
            $service->downloadPreparation($lab, $actor, $order->id, $report->id, $preparations[1]->id);
        }

        $dir = sys_get_temp_dir().'/be092-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($dir.'/pid', (string) DB::scalar('SELECT pg_backend_pid()'));
                $this->awaitFile($dir.'/go');
                $childService = app(ReportDeliveryService::class);
                match ($race) {
                    'two_authorizations' => $childService->authorizeException($lab, $actor, $order->id, $report->id, $exception['id'], ['revision' => 1]),
                    'exception_reuse' => $childService->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparations[2]->id, 'delivery_confirmed' => true], 'race-second-exception-delivery'),
                    'same_idempotency_key' => $childService->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparations[1]->id, 'delivery_confirmed' => true], 'race-shared-delivery-key'),
                    default => $childService->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparations[1]->id, 'delivery_confirmed' => true], 'race-child-delivery-key'),
                };
                $result = ['status' => 'ok'];
            } catch (ClinicalConflict $e) {
                $result = ['status' => $e->errorCode];
            } catch (Throwable $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
            }
            file_put_contents($dir.'/result', json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            exit(0);
        }

        try {
            DB::reconnect();
            DB::beginTransaction();
            LaboratoryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            match ($race) {
                'two_authorizations' => $service->authorizeException($lab, $actor, $order->id, $report->id, $exception['id'], ['revision' => 1]),
                'exception_reuse' => $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparations[1]->id, 'delivery_confirmed' => true], 'race-first-exception-delivery'),
                'same_idempotency_key' => $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparations[1]->id, 'delivery_confirmed' => true], 'race-shared-delivery-key'),
                'same_preparation' => $service->deliver($lab, $actor, $order->id, $report->id, ['preparation_id' => $preparations[1]->id, 'delivery_confirmed' => true], 'race-parent-delivery-key'),
                'policy_change' => app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 1, 'mode' => 'payment_or_credit', 'reason' => 'FICTICIO: cambio concurrente.']),
                'commercial_evidence_change' => DB::table('laboratory_orders')->where('id', $order->id)->update(['notes' => 'evidence-v2']),
            };
            touch($dir.'/go');
            $this->assertTrue($this->awaitBlocked($dir));
            DB::commit();
            pcntl_waitpid($pid, $exit);
            $this->assertSame(0, pcntl_wexitstatus($exit));
            $result = json_decode(file_get_contents($dir.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $result['status'], $result['message'] ?? '');
            $this->assertSame(in_array($race, ['exception_reuse', 'same_idempotency_key', 'same_preparation'], true) ? 1 : 0, DB::table('report_deliveries')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            touch($dir.'/go');
            pcntl_waitpid($pid, $exit);
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public static function deliveryRaces(): array
    {
        return [
            'two exception authorizations' => ['two_authorizations', 'report_delivery_exception_revision_conflict'],
            'one exception cannot back two deliveries' => ['exception_reuse', 'report_delivery_exception_unavailable'],
            'same delivery retry is idempotent' => ['same_idempotency_key', 'ok'],
            'one preparation cannot back two deliveries' => ['same_preparation', 'report_delivery_preparation_consumed'],
            'policy change invalidates preparation' => ['policy_change', 'report_delivery_evidence_changed'],
            'commercial evidence change invalidates preparation' => ['commercial_evidence_change', 'report_delivery_evidence_changed'],
        ];
    }

    private function databaseBackedProvider(): void
    {
        $this->app->instance(CommercialComplianceProvider::class, new class implements CommercialComplianceProvider
        {
            public function assess(Laboratory $laboratory, LaboratoryOrder $order): CommercialAssessment
            {
                $revision = (string) LaboratoryOrder::forLaboratory($laboratory)->whereKey($order->id)->value('notes');

                return new CommercialAssessment('satisfied', 'full_payment', 'test_database_provider', 'TEST-EVIDENCE', $revision);
            }
        });
        $this->app->forgetInstance(ReportDeliveryService::class);
    }

    private function awaitBlocked(string $dir): bool
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (file_exists($dir.'/pid')) {
                $blocked = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock')", [(int) file_get_contents($dir.'/pid')]);
                if ($blocked || file_exists($dir.'/result')) {
                    return $blocked;
                }
            }
            usleep(1000);
        }

        return false;
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (! file_exists($path)) {
            throw new \RuntimeException('Race barrier timeout.');
        }
    }
}
