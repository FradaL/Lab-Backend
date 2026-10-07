<?php

namespace Tests\Feature\Audit;

use App\Audit\MasterDataAuditEvents;
use App\Models\AuditLog;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Patient;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class MasterDataAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_audit_is_private_semantic_and_ignores_noops_and_reads(): void
    {
        [$actor, $laboratory] = $this->tenant();
        $id = $this->request($actor, $laboratory)->postJson('/api/v1/patients', [
            'first_names' => 'Ana', 'last_names' => 'López', 'phone' => '1111',
        ])->assertCreated()->json('data.id');

        $this->request($actor, $laboratory)->patchJson("/api/v1/patients/{$id}", [
            'first_names' => 'María', 'phone' => '2222',
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/patients/{$id}", [
            'first_names' => 'María', 'phone' => '2222',
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/patients/{$id}/status", ['status' => 'inactive'])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/patients/{$id}/status", ['status' => 'inactive'])->assertOk();
        $this->request($actor, $laboratory)->getJson("/api/v1/patients/{$id}")->assertOk();
        $this->request($actor, $laboratory)->getJson('/api/v1/patients')->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([
            MasterDataAuditEvents::PATIENT_CREATED,
            MasterDataAuditEvents::PATIENT_UPDATED,
            MasterDataAuditEvents::PATIENT_STATUS_CHANGED,
        ], $logs->pluck('event')->all());
        $this->assertSame(['status' => 'active'], $logs[0]->new_values);
        $this->assertNull($logs[1]->old_values);
        $this->assertNull($logs[1]->new_values);
        $this->assertSame(['changed_fields' => ['first_names', 'phone']], $logs[1]->metadata);
        $this->assertSame(['status' => 'active'], $logs[2]->old_values);
        $this->assertSame(['status' => 'inactive'], $logs[2]->new_values);
        $this->assertSame([$laboratory->id], $logs->pluck('laboratory_id')->unique()->values()->all());
        $this->assertSame([$actor->id], $logs->pluck('user_id')->unique()->values()->all());
        $this->assertSame(['patient'], $logs->pluck('auditable_type')->unique()->values()->all());
        $this->assertStringNotContainsString('Ana', $logs->toJson());
        $this->assertStringNotContainsString('2222', $logs->toJson());
    }

    public function test_doctor_audit_uses_changed_fields_without_personal_values(): void
    {
        [$actor, $laboratory] = $this->tenant();
        $id = $this->request($actor, $laboratory)->postJson('/api/v1/doctors', [
            'first_names' => 'Juan', 'last_names' => 'Pérez', 'license_number' => 'COL-1',
        ])->assertCreated()->json('data.id');
        $this->request($actor, $laboratory)->patchJson("/api/v1/doctors/{$id}", [
            'specialty' => 'Cardiología', 'license_number' => 'COL-2',
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/doctors/{$id}", [
            'specialty' => 'Cardiología', 'license_number' => 'COL-2',
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/doctors/{$id}/status", ['status' => 'inactive'])->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(3, $logs->count());
        $this->assertSame(['status' => 'active'], $logs[0]->new_values);
        $this->assertSame(['changed_fields' => ['license_number', 'specialty']], $logs[1]->metadata);
        $this->assertNull($logs[1]->old_values);
        $this->assertNull($logs[1]->new_values);
        $this->assertStringNotContainsString('COL-2', $logs->toJson());
        $this->assertSame(MasterDataAuditEvents::DOCTOR_STATUS_CHANGED, $logs[2]->event);
    }

    public function test_exam_audit_has_exact_allowlisted_deltas_and_noop_behavior(): void
    {
        [$actor, $laboratory] = $this->tenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $id = $this->request($actor, $laboratory)->postJson('/api/v1/laboratory-exams', [
            'laboratory_area_id' => $area->id, 'sample_type_id' => $sample->id,
            'code' => 'GLU', 'name' => 'Glucosa', 'description' => null,
            'turnaround_time_minutes' => 30,
        ])->assertCreated()->json('data.id');
        $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-exams/{$id}", [
            'name' => 'Glucosa sérica', 'turnaround_time_minutes' => 45,
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-exams/{$id}", [
            'name' => 'Glucosa sérica', 'turnaround_time_minutes' => 45,
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-exams/{$id}/status", ['status' => 'inactive'])->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(3, $logs->count());
        $this->assertEqualsCanonicalizing([
            'laboratory_area_id', 'sample_type_id', 'code', 'name', 'description',
            'turnaround_time_minutes', 'status',
        ], array_keys($logs[0]->new_values));
        $this->assertSame(['name' => 'Glucosa', 'turnaround_time_minutes' => 30], $logs[1]->old_values);
        $this->assertSame(['name' => 'Glucosa sérica', 'turnaround_time_minutes' => 45], $logs[1]->new_values);
        $this->assertSame(MasterDataAuditEvents::LABORATORY_EXAM_STATUS_CHANGED, $logs[2]->event);
    }

    public function test_commercial_client_audit_keeps_contact_values_private(): void
    {
        [$actor, $laboratory] = $this->tenant();
        $id = $this->request($actor, $laboratory)->postJson('/api/v1/commercial-clients', [
            'name' => 'Seguros Uno', 'type' => 'insurance', 'phone' => '1111',
        ])->assertCreated()->json('data.id');
        $this->request($actor, $laboratory)->patchJson("/api/v1/commercial-clients/{$id}", [
            'name' => 'Seguros Dos', 'phone' => '2222',
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/commercial-clients/{$id}", [
            'name' => 'Seguros Dos', 'phone' => '2222',
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/commercial-clients/{$id}/status", ['status' => 'inactive'])->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(3, $logs->count());
        $this->assertSame(['name' => 'Seguros Uno', 'type' => 'insurance', 'status' => 'active'], $logs[0]->new_values);
        $this->assertSame(['name' => 'Seguros Uno'], $logs[1]->old_values);
        $this->assertSame(['name' => 'Seguros Dos'], $logs[1]->new_values);
        $this->assertSame(['changed_fields' => ['name', 'phone']], $logs[1]->metadata);
        $this->assertStringNotContainsString('2222', $logs->toJson());
    }

    public function test_area_and_sample_type_audit_exact_deltas(): void
    {
        [$actor, $laboratory] = $this->tenant();
        $areaId = $this->request($actor, $laboratory)->postJson('/api/v1/laboratory-areas', [
            'code' => 'HEM', 'name' => 'Hematología', 'description' => 'Inicial',
        ])->assertCreated()->json('data.id');
        $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-areas/{$areaId}", [
            'name' => 'Hematología clínica', 'description' => null,
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-areas/{$areaId}", [
            'name' => 'Hematología clínica', 'description' => null,
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-areas/{$areaId}/status", ['status' => 'inactive'])->assertOk();

        $sampleId = $this->request($actor, $laboratory)->postJson('/api/v1/sample-types', ['name' => 'Sangre'])->assertCreated()->json('data.id');
        $this->request($actor, $laboratory)->patchJson("/api/v1/sample-types/{$sampleId}", ['name' => 'Sangre venosa'])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/sample-types/{$sampleId}", ['name' => 'Sangre venosa'])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/sample-types/{$sampleId}/status", ['status' => 'inactive'])->assertOk();

        $this->assertSame(6, AuditLog::query()->count());
        $areaUpdate = AuditLog::query()->where('event', MasterDataAuditEvents::LABORATORY_AREA_UPDATED)->sole();
        $this->assertSame(['name' => 'Hematología', 'description' => 'Inicial'], $areaUpdate->old_values);
        $this->assertSame(['name' => 'Hematología clínica', 'description' => null], $areaUpdate->new_values);
        $sampleUpdate = AuditLog::query()->where('event', MasterDataAuditEvents::SAMPLE_TYPE_UPDATED)->sole();
        $this->assertSame(['name' => 'Sangre'], $sampleUpdate->old_values);
        $this->assertSame(['name' => 'Sangre venosa'], $sampleUpdate->new_values);
    }

    public function test_cross_tenant_mutation_creates_no_log_and_effective_membership_is_used(): void
    {
        $actor = User::factory()->create();
        [, $laboratoryA] = $this->tenant($actor);
        [, $laboratoryB] = $this->tenant($actor);
        $patient = Patient::factory()->for($laboratoryA)->create();

        $this->request($actor, $laboratoryB)
            ->patchJson("/api/v1/patients/{$patient->id}", ['first_names' => 'Intruso'])
            ->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);

        $patientB = $this->request($actor, $laboratoryB)->postJson('/api/v1/patients', [
            'first_names' => 'Tenant', 'last_names' => 'B',
        ])->assertCreated()->json('data.id');
        $patientA = $this->request($actor, $laboratoryA)->postJson('/api/v1/patients', [
            'first_names' => 'Tenant', 'last_names' => 'A',
        ])->assertCreated()->json('data.id');

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([$laboratoryB->id, $laboratoryA->id], $logs->pluck('laboratory_id')->all());
        $this->assertSame([$patientB, $patientA], $logs->pluck('auditable_id')->all());
        $this->assertSame([$actor->id], $logs->pluck('user_id')->unique()->values()->all());
        $this->assertSame($laboratoryB->id, Patient::query()->findOrFail($patientB)->laboratory_id);
        $this->assertSame($laboratoryA->id, Patient::query()->findOrFail($patientA)->laboratory_id);
    }

    public function test_catalog_updates_do_not_rewrite_order_snapshots(): void
    {
        [$actor, $laboratory] = $this->tenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Cliente histórico', 'type' => CommercialClient::TYPE_COMPANY,
        ]);
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'code' => 'OLD', 'name' => 'Examen histórico',
        ]);
        $order = LaboratoryOrder::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'commercial_client_name' => 'Cliente histórico',
            'commercial_client_type' => CommercialClient::TYPE_COMPANY,
            'created_by' => $actor->id,
        ]);
        $line = LaboratoryOrderExam::factory()->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $order->price_list_id,
            'exam_code' => 'OLD',
            'exam_name' => 'Examen histórico',
        ]);

        $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-exams/{$exam->id}", [
            'code' => 'NEW', 'name' => 'Examen actual',
        ])->assertOk();
        $this->request($actor, $laboratory)->patchJson("/api/v1/commercial-clients/{$client->id}", [
            'name' => 'Cliente actual', 'type' => CommercialClient::TYPE_INSURANCE,
        ])->assertOk();

        $this->assertSame('OLD', $line->fresh()->exam_code);
        $this->assertSame('Examen histórico', $line->fresh()->exam_name);
        $this->assertSame('Cliente histórico', $order->fresh()->commercial_client_name);
        $this->assertSame(CommercialClient::TYPE_COMPANY, $order->fresh()->commercial_client_type);
        $this->assertSame([
            MasterDataAuditEvents::LABORATORY_EXAM_UPDATED,
            MasterDataAuditEvents::COMMERCIAL_CLIENT_UPDATED,
        ], AuditLog::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_audit_failure_rolls_back_create_update_and_status_across_families(): void
    {
        [$actor, $laboratory] = $this->tenant();
        $patient = Patient::factory()->for($laboratory)->create(['first_names' => 'Original']);
        $exam = LaboratoryExam::factory()->for($laboratory)->create(['name' => 'Original exam']);
        $client = CommercialClient::factory()->for($laboratory)->create(['status' => 'active']);
        $doctor = Doctor::factory()->for($laboratory)->create(['first_names' => 'Original doctor']);
        $area = LaboratoryArea::factory()->for($laboratory)->create(['name' => 'Original area']);
        $sample = SampleType::factory()->for($laboratory)->create(['status' => 'active']);

        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->postJson('/api/v1/patients', [
            'first_names' => 'Rollback', 'last_names' => 'Create',
        ]));
        $this->assertDatabaseMissing('patients', ['first_names' => 'Rollback']);
        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/patients/{$patient->id}", ['first_names' => 'Changed']));
        $this->assertSame('Original', $patient->fresh()->first_names);
        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/patients/{$patient->id}/status", ['status' => 'inactive']));
        $this->assertSame('active', $patient->fresh()->status);

        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->postJson('/api/v1/laboratory-exams', [
            'laboratory_area_id' => $exam->laboratory_area_id,
            'sample_type_id' => $exam->sample_type_id,
            'code' => 'ROLLBACK',
            'name' => 'Rollback create',
        ]));
        $this->assertDatabaseMissing('laboratory_exams', ['code' => 'ROLLBACK']);
        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-exams/{$exam->id}", ['name' => 'Changed']));
        $this->assertSame('Original exam', $exam->fresh()->name);
        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-exams/{$exam->id}/status", ['status' => 'inactive']));
        $this->assertSame('active', $exam->fresh()->status);

        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->postJson('/api/v1/commercial-clients', [
            'name' => 'Rollback client', 'type' => CommercialClient::TYPE_COMPANY,
        ]));
        $this->assertDatabaseMissing('commercial_clients', ['name' => 'Rollback client']);
        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/commercial-clients/{$client->id}", ['name' => 'Changed']));
        $this->assertNotSame('Changed', $client->fresh()->name);
        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/commercial-clients/{$client->id}/status", ['status' => 'inactive']));
        $this->assertSame('active', $client->fresh()->status);

        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/doctors/{$doctor->id}", ['first_names' => 'Changed']));
        $this->assertSame('Original doctor', $doctor->fresh()->first_names);

        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/laboratory-areas/{$area->id}", ['name' => 'Changed']));
        $this->assertSame('Original area', $area->fresh()->name);

        $this->assertAuditFailure(fn () => $this->request($actor, $laboratory)->patchJson("/api/v1/sample-types/{$sample->id}/status", ['status' => 'inactive']));
        $this->assertSame('active', $sample->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('Original', $patient->fresh()->first_names);
    }

    private function assertAuditFailure(callable $mutation): void
    {
        AuditLog::creating(static fn (): never => throw new RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();

        try {
            $mutation();
            $this->fail('The audit failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    /** @return array{User, Laboratory} */
    private function tenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'trial_ends_at' => null,
        ]);
        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'patients.view', 'patients.create', 'patients.update', 'patients.change_status',
            'doctors.create', 'doctors.update', 'doctors.change_status',
            'laboratory_areas.create', 'laboratory_areas.update', 'laboratory_areas.change_status',
            'sample_types.create', 'sample_types.update', 'sample_types.change_status',
            'laboratory_exams.create', 'laboratory_exams.update', 'laboratory_exams.change_status',
            'commercial_clients.create', 'commercial_clients.update', 'commercial_clients.change_status',
        ]);

        return [$user, $laboratory];
    }

    private function request(User $actor, Laboratory $laboratory): static
    {
        return $this->actingAs($actor, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }
}
