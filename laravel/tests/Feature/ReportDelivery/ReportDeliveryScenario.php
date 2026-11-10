<?php

namespace Tests\Feature\ReportDelivery;

use App\ClinicalReports\ClinicalReportService;
use App\Cash\RecordOrderPayment;
use App\Models\LaboratoryOrder;
use App\Models\ReportDeliveryPolicy;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ClinicalReports\ClinicalReportScenario;

trait ReportDeliveryScenario
{
    use ClinicalReportScenario;

    private ?string $deliveryPolicyMode = null;

    protected function makeScenarioOrder($lab, $prices): LaboratoryOrder
    {
        if ($this->deliveryPolicyMode !== null) {
            ReportDeliveryPolicy::factory()->for($lab)->create(['mode' => $this->deliveryPolicyMode]);
        }

        return LaboratoryOrder::factory()->for($lab)->create([
            'price_list_id' => $prices->id, 'currency' => 'GTQ', 'status' => 'pending',
        ]);
    }

    protected function issued(): array
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'delivery-scenario-report-key');

        return [$lab, $actor, $order, $line, $report];
    }

    protected function issuedForPolicy(string $mode): array
    {
        $this->deliveryPolicyMode = $mode;
        try {
            return $this->issued();
        } finally {
            $this->deliveryPolicyMode = null;
        }
    }

    protected function recordPayment($lab, $actor, LaboratoryOrder $order, string $amount, string $key): array
    {
        $revision = (int) DB::table('laboratory_order_financial_states')
            ->where(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id])
            ->value('revision');

        return app(RecordOrderPayment::class)->execute($lab, $actor, $order->id, [
            'expected_revision' => $revision,
            'amount' => $amount,
            'method' => 'cash',
        ], $key);
    }

    protected function settlementAmount(LaboratoryOrder $order): string
    {
        $total = $order->fresh()->total;

        return $total === '0.00' ? '0.01' : $total;
    }

    protected function recipient(string $channel = 'printed_document', array $extra = []): array
    {
        return $extra + ['channel' => $channel, 'recipient_type' => 'patient', 'authorization_basis' => 'identity_verified',
            'authorization_evidence' => 'FICTICIO: identidad comprobada directamente para la prueba.', 'authorization_confirmed' => true];
    }

    protected function exceptionAuthorization($lab, $actor, $order, $report): array
    {
        $service = app(ReportDeliveryService::class);
        $exception = $service->requestException($lab, $actor, $order->id, $report->id, ['reason' => 'FICTICIO: excepción comercial demostrativa.']);

        return $service->authorizeException($lab, $actor, $order->id, $report->id, $exception['id'], ['revision' => 1]);
    }
}
