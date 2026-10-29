<?php

namespace Tests\Feature\ClinicalReplacements;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\SampleType;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

trait ReplacementScenario
{
    protected function replacementScenario(): array
    {
        $lab = Laboratory::factory()->create();
        $actor = $this->createLaboratoryMember($lab);
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assignDirectLaboratoryPermissions($actor, $lab, RbacCatalog::PERMISSIONS);
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);
        $branch = Branch::factory()->for($lab)->create(['status' => 'active']);
        $type = SampleType::factory()->for($lab)->create(['status' => 'active']);
        $area = LaboratoryArea::factory()->for($lab)->create(['status' => 'active']);
        $clinical = app(ClinicalExamService::class);
        $draft = $clinical->create($lab, $actor, ['code' => 'SYNTHETIC', 'name' => 'FICTICIO: prueba', 'laboratory_area_id' => $area->id,
            'configuration' => ['schema_version' => 2, 'sample_mode' => 'physical', 'sample_requirements' => [['key' => 'main', 'sample_type_id' => $type->id]], 'parameters' => [['code' => 'OBS', 'name' => 'FICTICIO', 'type' => 'text']]]]);
        $clinical->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1);
        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
        $exam->update(['status' => 'active']);
        $prices = PriceList::factory()->for($lab)->create(['status' => 'active', 'currency' => 'GTQ']);
        PriceListExam::factory()->for($lab)->create(['price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id, 'status' => 'active']);
        $order = LaboratoryOrder::factory()->for($lab)->create(['status' => 'pending', 'price_list_id' => $prices->id, 'currency' => 'GTQ']);
        $line = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $exam->id, $actor);
        $requirement = $line->sampleRequirements->sole();
        $url = "/api/v1/laboratory-orders/$order->id/exams/$line->id/clinical-replacements";

        setPermissionsTeamId($lab->id);

        return [$lab, $actor, $order, $line, $requirement, $branch, $type, $url];
    }

    protected function intentionBody($requirement, int $revision = 0, ?int $sampleId = null): array
    {
        return ['expected_revision' => $revision, 'occurred_at' => now()->subMinute()->toISOString(), 'sample_requirement_id' => $requirement->id,
            'sample_id' => $sampleId, 'reason' => 'FICTICIO: material insuficiente', 'justification' => 'FICTICIO: obtener material adicional.'];
    }

    protected function evidenceBody(int $revision = 0, string $kind = 'sample_problem'): array
    {
        $facts = [];
        foreach (ClinicalReplacementService::EVIDENCE_FIELDS[$kind] as $field) {
            $facts[$field] = $field === 'express_refusal' ? true : 'FICTICIO: hecho documentado';
        }

        return ['expected_revision' => $revision, 'occurred_at' => now()->subMinute()->toISOString(), 'kind' => $kind, 'facts' => $facts];
    }

    protected function assessmentBody(int $revision, int $intention, int $evidence, ?int $previous = null, string $outcome = 'viable'): array
    {
        $lineId = DB::table('clinical_replacement_intents')->where('id', $intention)->value('laboratory_order_exam_id') ?? DB::table('clinical_replacement_contexts')->value('laboratory_order_exam_id');
        $token = hash('sha256', DB::scalar('SELECT clinical_replacement_dependencies(?)::text', [$lineId]));

        return ['expected_dependency_token' => $token, 'expected_revision' => $revision, 'occurred_at' => now()->subMinute()->toISOString(), 'subject' => 'replacement', 'intention_id' => $intention,
            'previous_id' => $previous, 'outcome' => $outcome, 'justification' => 'FICTICIO: evaluación clínica formal', 'evidence_ids' => [$evidence]];
    }
}
