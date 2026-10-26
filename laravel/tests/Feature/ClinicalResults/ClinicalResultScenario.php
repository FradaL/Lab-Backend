<?php

namespace Tests\Feature\ClinicalResults;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use Tests\Support\StructuredClinicalReasonFixture;

trait ClinicalResultScenario
{
    protected function exceptionReference($lab, $actor, string $name = 'FICTICIO'): array
    {
        return StructuredClinicalReasonFixture::forLaboratory($lab, $actor, $name);
    }

    protected function scenario(bool $start = true, array $parameterOverrides = []): array
    {
        $lab = Laboratory::factory()->create();
        $actor = $this->createLaboratoryMember($lab);
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assignDirectLaboratoryPermissions($actor, $lab, RbacCatalog::PERMISSIONS);
        setPermissionsTeamId($lab->id);
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);
        $area = LaboratoryArea::factory()->for($lab)->create(['status' => 'active']);
        $draft = app(ClinicalExamService::class)->create($lab, $actor, ['code' => 'DEMO', 'name' => 'FICTITIOUS', 'laboratory_area_id' => $area->id,
            'sample_type_id' => null, 'configuration' => ['sample_mode' => 'none', 'parameters' => [
                ['code' => 'N', 'name' => 'Fictional number', 'type' => 'numeric', 'unit_required' => true, 'unit' => 'DEMO-U'],
                ['code' => 'T', 'name' => 'Fictional text', 'type' => 'text', 'text_max_length' => 10],
                ['code' => 'Q', 'name' => 'Fictional qualitative', 'type' => 'qualitative', 'options' => [['code' => 'YES', 'label' => 'Fictitious YES'], ['code' => 'NO', 'label' => 'Fictitious NO'], ['code' => '01', 'label' => 'Fictitious zero-one'], ['code' => '1', 'label' => 'Fictitious one']]],
                ['code' => 'O', 'name' => 'Fictional option', 'type' => 'predefined_options', 'options' => [['code' => 'A', 'label' => 'Fictitious A']]],
            ]]]);
        if ($parameterOverrides !== []) {
            $configuration = $draft->configuration;
            foreach ($configuration['parameters'] as &$parameter) {
                $parameter = array_replace($parameter, $parameterOverrides[$parameter['code']] ?? []);
            }
            unset($parameter);
            $draft = app(ClinicalExamService::class)->update($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1, $configuration);
        }
        app(ClinicalExamService::class)->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, $draft->revision);
        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
        $exam->update(['status' => 'active']);
        $prices = PriceList::factory()->for($lab)->create(['status' => 'active', 'currency' => 'GTQ']);
        PriceListExam::factory()->for($lab)->create(['price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id, 'status' => 'active']);
        $order = LaboratoryOrder::factory()->for($lab)->create(['price_list_id' => $prices->id, 'currency' => 'GTQ', 'status' => 'pending']);
        $line = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $exam->id, $actor);
        if ($start) {
            app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
        }
        $url = "/api/v1/laboratory-orders/$order->id/exams/$line->id/results";

        return [$lab, $actor, $order, $line, $url];
    }
}
