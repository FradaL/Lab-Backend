<?php

namespace Database\Seeders;

use App\ClinicalReports\ClinicalReportService;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\ReportDeliveryException;
use App\Models\ReportDeliveryExceptionAuthorization;
use App\Models\User;
use App\ReportDelivery\ReportDeliveryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Explicit opt-in: emits private PDFs and then provisions auditable delivery examples. */
final class LaboratoryReportDeliverySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing', 'local') || DB::transactionLevel() !== 0) {
            throw new LogicException('Explicit demo report delivery requires local/testing and no outer transaction.');
        }
        $this->call(LaboratoryReportIssuanceSeeder::class);
        $team = getPermissionsTeamId();
        try {
            [$freeLab,$freeActor,$freeOrder] = $this->dependencies('DEMO-BE091-1', 'be091-manager@donqerlab.test', 'report_demo_order', 'be091-complete');
            setPermissionsTeamId($freeLab->id);
            $freeReport = app(ClinicalReportService::class)->issue($freeLab, $freeActor, $freeOrder->id,
                ['type' => 'complete', 'clinical_revision' => $freeOrder->clinical_revision], 'demo-be091-emission-complete-v1');
            $delivery = app(ReportDeliveryService::class);
            foreach (['printed_document', 'manual_digital_file'] as $index => $channel) {
                $key = $index + 1;
                $preparation = $delivery->prepare($freeLab, $freeActor, $freeOrder->id, $freeReport->id, $this->recipient($channel), "demo-be092-free-preparation-$key");
                if ($preparation->downloaded_at === null) {
                    $delivery->downloadPreparation($freeLab, $freeActor, $freeOrder->id, $freeReport->id, $preparation->id);
                }
                $delivery->deliver($freeLab, $freeActor, $freeOrder->id, $freeReport->id,
                    ['preparation_id' => $preparation->id, 'delivery_confirmed' => true, 'notes' => DemoDataset::DISCLAIMER], "demo-be092-free-delivery-$key");
            }

            [$strictLab,$strictActor,$strictOrder] = $this->dependencies('DEMO-BE092-1', 'be092-manager@donqerlab.test', 'report_delivery_demo_order', 'be092-restrictive');
            setPermissionsTeamId($strictLab->id);
            $strictService = app(ClinicalReportService::class);
            $deliveredReport = $strictService->issue($strictLab, $strictActor, $strictOrder->id,
                ['type' => 'complete', 'clinical_revision' => $strictOrder->clinical_revision], 'demo-be092-restrictive-delivered-report');
            $authorizedReport = $strictService->issue($strictLab, $strictActor, $strictOrder->id,
                ['type' => 'complete', 'clinical_revision' => $strictOrder->clinical_revision], 'demo-be092-restrictive-authorized-report');
            $strictDelivery = app(ReportDeliveryService::class);
            $usedAuthorization = $this->authorization($strictLab, $strictActor, $strictOrder, $deliveredReport, 'used');
            $preparation = $strictDelivery->prepare($strictLab, $strictActor, $strictOrder->id, $deliveredReport->id,
                $this->recipient('printed_document', $usedAuthorization->id), 'demo-be092-restrictive-preparation');
            if ($preparation->downloaded_at === null) {
                $strictDelivery->downloadPreparation($strictLab, $strictActor, $strictOrder->id, $deliveredReport->id, $preparation->id);
            }
            $strictDelivery->deliver($strictLab, $strictActor, $strictOrder->id, $deliveredReport->id,
                ['preparation_id' => $preparation->id, 'delivery_confirmed' => true, 'notes' => DemoDataset::DISCLAIMER], 'demo-be092-restrictive-delivery');
            $this->authorization($strictLab, $strictActor, $strictOrder, $authorizedReport, 'unused');
        } finally {
            setPermissionsTeamId($team);
        }
    }

    private function dependencies(string $nit, string $email, string $orderKind, string $orderKey): array
    {
        $lab = DemoDataset::owned(Laboratory::class, ['nit' => $nit]);
        $actor = DemoDataset::owned(User::class, ['email' => $email]);
        $datasetId = DB::table('demo_datasets')->where('dataset_key', DemoDataset::KEY)->value('id');
        $orderId = DB::table('demo_dataset_records')->where('demo_dataset_id', $datasetId)->where('entity_kind', $orderKind)->where('entity_key', $orderKey)->value('entity_id');

        return [$lab, $actor, LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->firstOrFail()];
    }

    private function authorization(Laboratory $lab, User $actor, LaboratoryOrder $order, $report, string $key): ReportDeliveryExceptionAuthorization
    {
        $exception = DemoDataset::run(fn () => DemoDataset::remember('report_delivery_exception', "be092-$key", fn ($id) => ReportDeliveryException::forLaboratory($lab)->find($id), function () use ($lab, $actor, $order, $report) {
            $result = app(ReportDeliveryService::class)->requestException($lab, $actor, $order->id, $report->id, ['reason' => DemoDataset::DISCLAIMER]);

            return ReportDeliveryException::findOrFail($result['id']);
        }));

        return DemoDataset::run(fn () => DemoDataset::remember(ReportDeliveryExceptionAuthorization::class, "be092-$key", fn ($id) => ReportDeliveryExceptionAuthorization::forLaboratory($lab)->find($id), function () use ($lab, $actor, $order, $report, $exception) {
            $result = app(ReportDeliveryService::class)->authorizeException($lab, $actor, $order->id, $report->id, $exception->id, ['revision' => 1]);

            return ReportDeliveryExceptionAuthorization::findOrFail($result['authorization']['id']);
        }));
    }

    private function recipient(string $channel, ?int $authorization = null): array
    {
        return array_filter(['channel' => $channel, 'recipient_type' => 'patient', 'authorization_basis' => 'identity_verified',
            'authorization_evidence' => DemoDataset::DISCLAIMER, 'authorization_confirmed' => true,
            'exception_authorization_id' => $authorization], fn ($value) => $value !== null);
    }
}
