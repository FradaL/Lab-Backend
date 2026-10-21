<?php

namespace Tests\Feature\ReportDelivery;

use App\ClinicalReports\ClinicalReportService;
use App\ReportDelivery\ReportDeliveryService;
use Tests\Feature\ClinicalReports\ClinicalReportScenario;

trait ReportDeliveryScenario
{
    use ClinicalReportScenario;

    protected function issued(): array
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $this->body($order, $line), 'delivery-scenario-report-key');

        return [$lab, $actor, $order, $line, $report];
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
