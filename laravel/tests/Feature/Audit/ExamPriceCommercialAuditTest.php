<?php

namespace Tests\Feature\Audit;

use App\Audit\CommercialAuditEvents;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ExamPriceCommercialAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_upsert_distinguishes_create_change_and_noop_with_exact_decimals(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $exam = $this->exam($laboratory);
        $uri = "/api/v1/price-lists/{$priceList->id}/exams/{$exam->id}";

        $this->request($actor, $laboratory)->putJson($uri, ['price' => '35'])->assertCreated();

        $created = AuditLog::query()->sole();
        $subjectId = PriceListExam::query()->sole()->id;
        $this->assertSame(CommercialAuditEvents::EXAM_PRICE_CREATED, $created->event);
        $this->assertSame(CommercialAuditEvents::SUBJECT_PRICE_LIST_EXAM, $created->auditable_type);
        $this->assertSame($subjectId, $created->auditable_id);
        $this->assertSame($actor->id, $created->user_id);
        $this->assertSame($laboratory->id, $created->laboratory_id);
        $this->assertNull($created->old_values);
        $this->assertEquals([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '35.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ], $created->new_values);

        $this->request($actor, $laboratory)->putJson($uri, ['price' => '40'])->assertOk();
        $changed = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame(CommercialAuditEvents::EXAM_PRICE_CHANGED, $changed->event);
        $this->assertEquals(['price' => '35.00'], $changed->old_values);
        $this->assertEquals(['price' => '40.00'], $changed->new_values);

        $this->request($actor, $laboratory)->putJson($uri, ['price' => '40.00'])->assertOk();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_status_change_is_audited_and_same_state_is_not(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $exam = $this->exam($laboratory);
        $price = $this->price($laboratory, $priceList, $exam, ['status' => PriceListExam::STATUS_ACTIVE]);
        $uri = "/api/v1/price-lists/{$priceList->id}/exams/{$exam->id}/status";

        $this->request($actor, $laboratory)
            ->patchJson($uri, ['status' => PriceListExam::STATUS_INACTIVE])
            ->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame(CommercialAuditEvents::EXAM_PRICE_STATUS_CHANGED, $log->event);
        $this->assertSame($price->id, $log->auditable_id);
        $this->assertEquals(['status' => PriceListExam::STATUS_ACTIVE], $log->old_values);
        $this->assertEquals(['status' => PriceListExam::STATUS_INACTIVE], $log->new_values);

        $this->request($actor, $laboratory)
            ->patchJson($uri, ['status' => PriceListExam::STATUS_INACTIVE])
            ->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_mixed_bulk_writes_one_event_per_effective_mutation(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $unchangedExam = $this->exam($laboratory);
        $changedExam = $this->exam($laboratory);
        $newExam = $this->exam($laboratory);
        $unchanged = $this->price($laboratory, $priceList, $unchangedExam, ['price' => '10.00']);
        $changed = $this->price($laboratory, $priceList, $changedExam, ['price' => '20.00']);

        $this->request($actor, $laboratory)
            ->putJson("/api/v1/price-lists/{$priceList->id}/exams/bulk", ['items' => [
                ['laboratory_exam_id' => $unchangedExam->id, 'price' => '10.00'],
                ['laboratory_exam_id' => $changedExam->id, 'price' => '25.50'],
                ['laboratory_exam_id' => $newExam->id, 'price' => '30'],
            ]])
            ->assertOk()
            ->assertJsonPath('meta.created', 1)
            ->assertJsonPath('meta.updated', 1)
            ->assertJsonPath('meta.unchanged', 1);

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([
            CommercialAuditEvents::EXAM_PRICE_CHANGED,
            CommercialAuditEvents::EXAM_PRICE_CREATED,
        ], $logs->pluck('event')->all());
        $this->assertSame($changed->id, $logs[0]->auditable_id);
        $this->assertEquals(['price' => '20.00'], $logs[0]->old_values);
        $this->assertEquals(['price' => '25.50'], $logs[0]->new_values);
        $this->assertNotSame($unchanged->id, $logs[1]->auditable_id);
        $this->assertSame('30.00', $logs[1]->new_values['price']);
        $this->assertSame($actor->id, $logs[1]->user_id);
    }

    public function test_bulk_audit_failure_rolls_back_every_price_mutation(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $existingExam = $this->exam($laboratory);
        $newExam = $this->exam($laboratory);
        $existing = $this->price($laboratory, $priceList, $existingExam, ['price' => '10.00']);
        AuditLog::creating(static fn (): never => throw new RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();

        try {
            $this->request($actor, $laboratory)
                ->putJson("/api/v1/price-lists/{$priceList->id}/exams/bulk", ['items' => [
                    ['laboratory_exam_id' => $existingExam->id, 'price' => '15.00'],
                    ['laboratory_exam_id' => $newExam->id, 'price' => '20.00'],
                ]]);
            $this->fail('The audit failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertSame('10.00', $existing->fresh()->price);
        $this->assertDatabaseCount('price_list_exams', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_cross_tenant_and_actor_spoofing_write_no_audit(): void
    {
        $actor = User::factory()->create();
        [, $laboratory] = $this->activeTenant($actor);
        [, $foreignLaboratory] = $this->activeTenant($actor);
        $foreignList = PriceList::factory()->for($foreignLaboratory)->create();
        $foreignExam = $this->exam($foreignLaboratory);

        $this->request($actor, $laboratory)
            ->putJson("/api/v1/price-lists/{$foreignList->id}/exams/{$foreignExam->id}", ['price' => '10.00'])
            ->assertNotFound();

        $priceList = PriceList::factory()->for($laboratory)->create();
        $exam = $this->exam($laboratory);
        $this->request($actor, $laboratory)
            ->putJson("/api/v1/price-lists/{$priceList->id}/exams/{$exam->id}", [
                'price' => '10.00',
                'user_id' => User::factory()->create()->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [$user, $laboratory];
    }

    private function exam(Laboratory $laboratory): LaboratoryExam
    {
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType = SampleType::factory()->for($laboratory)->create();

        return LaboratoryExam::factory()->for($laboratory)->create([
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
        ]);
    }

    private function price(
        Laboratory $laboratory,
        PriceList $priceList,
        LaboratoryExam $exam,
        array $attributes = [],
    ): PriceListExam {
        return PriceListExam::factory()->create([
            'laboratory_id' => $laboratory->id,
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            ...$attributes,
        ]);
    }

    private function request(User $user, Laboratory $laboratory): static
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }
}
