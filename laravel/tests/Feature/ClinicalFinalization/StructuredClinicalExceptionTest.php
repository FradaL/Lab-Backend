<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\ClinicalExams\GlobalClinicalReasonImpactManager;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReports\ClinicalReportService;
use App\Models\AuditLog;
use App\Models\ClinicalExceptionDecision;
use App\Models\ClinicalExceptionReasonReference;
use App\Models\ClinicalExceptionRequest;
use App\Models\ClinicalLineResolution;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonAdoptionVersion;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\Support\StructuredClinicalReasonFixture;
use Tests\TestCase;

class StructuredClinicalExceptionTest extends TestCase
{
    use ClinicalResultScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    private function prepared(): array
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        $reference = $this->exceptionReference($lab, $actor, 'FICTICIO: motivo original');
        $operator = User::findOrFail(GlobalClinicalReasonVersion::findOrFail($reference['version_id'])->created_by);
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve', 'orders.clinical_exceptions.view', 'clinical_reasons.review_impact']);

        return [$lab, $actor, $order, $line, $reference, $operator, $approver];
    }

    private function body($order, array $reference): array
    {
        return ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO: justificación independiente.'];
    }

    private function url($order, $line): string
    {
        return "/api/v1/laboratory-orders/$order->id/exams/$line->id/exceptions";
    }

    private function impact(User $operator, array $reference, string $classification, int $expected = 1): void
    {
        app(GlobalClinicalReasonImpactManager::class)->declare($operator, $reference['reason_id'], $reference['version_id'], ['expected_revision' => $expected, 'classification' => $classification, 'justification' => 'FICTICIO: impacto actualizado.']);
    }

    public function test_real_http_request_and_independent_approval_preserve_structured_identity_and_audit(): void
    {
        [$lab, $actor, $order, $line, $reference, $operator, $approver] = $this->prepared();
        $url = $this->url($order, $line);
        $created = $this->postJson($url, $this->body($order, $reference))->assertCreated()
            ->assertJsonPath('data.exception.reason', 'FICTICIO: motivo original')
            ->assertJsonPath('data.reason_reference.version_id', $reference['version_id'])
            ->assertJsonPath('data.reason_reference.justification', 'FICTICIO: justificación independiente.');
        $id = $created->json('data.exception.id');
        $this->assertDatabaseHas('clinical_exception_reason_references', ['exception_request_id' => $id, 'laboratory_id' => $lab->id] + $reference);
        $this->postJson($url.'/'.$id.'/approve', ['clinical_revision' => $created->json('data.clinical_revision'), 'request_revision' => 1])->assertForbidden();
        $this->actingAs($approver)->postJson($url.'/'.$id.'/approve', ['clinical_revision' => $created->json('data.clinical_revision'), 'request_revision' => 1])->assertOk()->assertJsonPath('data.exception.status', 'approved');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.reason_reference.version_id', $reference['version_id']);
        $this->assertDatabaseCount('clinical_line_resolutions', 1);
        $this->assertDatabaseCount('laboratory_result_validations', 0);
        $event = AuditLog::where('event', 'order_clinical.exception_approved')->sole();
        $this->assertSame($reference['version_id'], $event->metadata['version_id']);
        $this->assertSame('allowed', $event->metadata['eligibility']['result']);
        $this->assertCount(1, $event->metadata['eligibility']['impact_declaration_ids']);
        $this->assertNull($line->fresh()->processing_started_at);
    }

    #[DataProvider('references')]
    public function test_new_http_requests_reject_invalid_or_unavailable_references(string $case, int $status): void
    {
        [$lab, $actor, $order, $line, $reference] = $this->prepared();
        $body = $this->body($order, $reference);
        if ($case === 'text_only') {
            unset($body['reason_reference']);
            $body['reason'] = 'FICTICIO: sustituto textual';
        } elseif ($case === 'unadopted') {
            [$unadopted, $version] = StructuredClinicalReasonFixture::global('FICTICIO: no adoptado');
            $body['reason_reference']['reason_id'] = $unadopted->id;
            $body['reason_reference']['version_id'] = $version->id;
        } elseif ($case === 'foreign') {
            [$other, $member] = $this->scenario(false);
            $body['reason_reference'] = $this->exceptionReference($other, $member);
            $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);
        } else {
            match ($case) {
                'reason' => $body['reason_reference']['reason_id'] = 999999,
                'adoption' => $body['reason_reference']['adoption_id'] = 999999,
                'version' => $body['reason_reference']['version_id'] = 999999,
                'selection' => $body['reason_reference']['adoption_version_id'] = 999999,
                'string' => $body['reason_reference']['version_id'] = (string) $reference['version_id'],
                'extra' => $body['reason_reference']['laboratory_id'] = $lab->id,
                'local' => $body['reason_reference']['source'] = 'local',
            };
        }
        $this->postJson($this->url($order, $line), $body)->assertStatus($status);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('clinical_exception_reason_references', 0);
    }

    public static function references(): array
    {
        return [['text_only', 422], ['unadopted', 404], ['foreign', 404], ['reason', 404], ['adoption', 404], ['version', 409], ['selection', 409], ['string', 422], ['extra', 422], ['local', 422]];
    }

    #[DataProvider('blockedImpacts')]
    public function test_real_creation_is_blocked_by_unclassified_or_restrictive_impact(string $impact): void
    {
        [$lab, $actor, $order, $line, $reference, $operator] = $this->prepared();
        $this->impact($operator, $reference, $impact);
        $this->postJson($this->url($order, $line), $this->body($order, $reference))->assertConflict()->assertJsonPath('code', 'clinical_reason_use_blocked');
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('clinical_exception_reason_references', 0);
    }

    public static function blockedImpacts(): array
    {
        return [['unclassified'], ['restrictive']];
    }

    public function test_approval_rechecks_impact_and_requires_favorable_evaluation_without_approving_automatically(): void
    {
        [$lab, $actor, $order, $line, $reference, $operator, $approver] = $this->prepared();
        $url = $this->url($order, $line);
        $created = $this->postJson($url, $this->body($order, $reference))->assertCreated();
        $id = $created->json('data.exception.id');
        $this->impact($operator, $reference, 'restrictive');
        $review = ClinicalReasonImpactReview::sole();
        $body = ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1];
        $this->actingAs($approver)->postJson($url.'/'.$id.'/approve', $body)->assertConflict()->assertJsonPath('code', 'clinical_reason_use_blocked');
        setPermissionsTeamId($lab->id);
        app(ClinicalReasonAdoptionManager::class)->resolve($lab, $approver, $reference['adoption_id'], $review->id, ['expected_revision' => 0, 'outcome' => 'non_favorable', 'assessment' => 'FICTICIO: no suficiente', 'justification' => 'FICTICIO']);
        $this->postJson($url.'/'.$id.'/approve', $body)->assertConflict();
        setPermissionsTeamId($lab->id);
        app(ClinicalReasonAdoptionManager::class)->resolve($lab, $approver, $reference['adoption_id'], $review->id, ['expected_revision' => 1, 'outcome' => 'favorable', 'assessment' => 'FICTICIO: evaluación favorable', 'justification' => 'FICTICIO']);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertDatabaseHas('clinical_exception_requests', ['id' => $id, 'status' => 'pending']);
        $this->postJson($url.'/'.$id.'/approve', $body)->assertOk();
        $this->assertDatabaseCount('clinical_line_resolutions', 1);
    }

    public function test_approval_uses_original_selection_after_adoption_changes_and_never_accepts_a_replacement(): void
    {
        [$lab, $actor, $order, $line, $reference, $operator, $approver] = $this->prepared();
        $service = app(ClinicalResolutionService::class);
        $created = $service->requestException($lab, $actor, $order->id, $line->id, $this->body($order, $reference));
        $old = ClinicalExceptionReasonReference::sole()->getRawOriginal();
        $definition = GlobalClinicalReasonVersion::findOrFail($reference['version_id'])->only(['name', 'description', 'classification', 'evidence_requirements', 'is_active', 'change_reason']);
        $definition['name'] = 'FICTICIO: versión posterior';
        $new = app(ClinicalReasonVersionStore::class)->appendGlobal($reference['reason_id'], $operator, 1, $definition);
        $newRef = $reference;
        $newRef['version_id'] = $new->id;
        $this->impact($operator, $newRef, 'non_restrictive', 0);
        ClinicalReasonAdoptionVersion::create(['laboratory_id' => $lab->id, 'reason_id' => $reference['reason_id'], 'adoption_id' => $reference['adoption_id'], 'version_id' => $new->id, 'revision' => 2, 'previous_id' => $reference['adoption_version_id'], 'created_by' => $actor->id, 'justification' => 'FICTICIO: actualización explícita de fixture']);
        $this->impact($operator, $reference, 'restrictive');
        $body = ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1];
        $url = $this->url($order, $line).'/'.$created['exception']['id'].'/approve';
        $this->actingAs($approver)->postJson($url, $body + ['reason_reference' => $newRef])->assertUnprocessable();
        $this->postJson($url, $body)->assertConflict()->assertJsonPath('code', 'clinical_reason_use_blocked');
        $this->impact($operator, $reference, 'non_restrictive', 2);
        $this->postJson($url, $body)->assertConflict();
        $review = ClinicalReasonImpactReview::sole();
        setPermissionsTeamId($lab->id);
        app(ClinicalReasonAdoptionManager::class)->resolve($lab, $approver, $reference['adoption_id'], $review->id, ['expected_revision' => 0, 'outcome' => 'favorable', 'assessment' => 'FICTICIO: revisado', 'justification' => 'FICTICIO']);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.reason_reference.version_id', $reference['version_id']);
        $this->assertSame($old, ClinicalExceptionReasonReference::sole()->getRawOriginal());
    }

    public function test_internal_service_applies_policy_and_review_permission_does_not_grant_approval(): void
    {
        [$lab, $actor, $order, $line, $reference, $operator, $approver] = $this->prepared();
        $this->impact($operator, $reference, 'restrictive');
        try {
            app(ClinicalResolutionService::class)->requestException($lab, $actor, $order->id, $line->id, $this->body($order, $reference));
            $this->fail('The internal service must apply eligibility.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('clinical_reason_use_blocked', $e->errorCode);
        }
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->impact($operator, $reference, 'non_restrictive', 2);
        $review = ClinicalReasonImpactReview::sole();
        setPermissionsTeamId($lab->id);
        app(ClinicalReasonAdoptionManager::class)->resolve($lab, $approver, $reference['adoption_id'], $review->id, ['expected_revision' => 0, 'outcome' => 'favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
        $created = $this->postJson($this->url($order, $line), $this->body($order, $reference))->assertCreated();
        setPermissionsTeamId($lab->id);
        $approver->revokePermissionTo('orders.clinical_exceptions.approve');
        $this->actingAs($approver)->postJson($this->url($order, $line).'/'.$created->json('data.exception.id').'/approve', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertForbidden();
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_pending_exception_prevents_finalization_and_can_be_rejected_despite_catalog_restriction(): void
    {
        [$lab, $actor, $order, $line, $reference, $operator, $approver] = $this->prepared();
        $created = $this->postJson($this->url($order, $line), $this->body($order, $reference))->assertCreated();
        $this->impact($operator, $reference, 'restrictive');
        $this->postJson("/api/v1/laboratory-orders/$order->id/finalize", ['clinical_revision' => $order->fresh()->clinical_revision])->assertConflict()->assertJsonPath('code', 'clinical_order_unresolved');
        $this->postJson("/api/v1/laboratory-orders/$order->id/exams/$line->id/finalize", ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0])->assertConflict()->assertJsonPath('code', 'clinical_exception_pending');
        $this->actingAs($approver)->postJson($this->url($order, $line).'/'.$created->json('data.exception.id').'/reject', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1, 'reason' => 'FICTICIO: rechazo independiente del impacto'])->assertOk();
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_restriction_does_not_block_an_independent_reason_in_the_same_laboratory(): void
    {
        [$lab, $actor, $order, $line, $reference, $operator] = $this->prepared();
        $this->impact($operator, $reference, 'restrictive');
        [$reason, $version] = StructuredClinicalReasonFixture::global('FICTICIO: independiente');
        $adoption = ClinicalReasonAdoption::create(['laboratory_id' => $lab->id, 'reason_id' => $reason->id, 'created_by' => $actor->id]);
        $selection = ClinicalReasonAdoptionVersion::create(['laboratory_id' => $lab->id, 'reason_id' => $reason->id, 'adoption_id' => $adoption->id, 'version_id' => $version->id, 'revision' => 1, 'created_by' => $actor->id, 'justification' => 'FICTICIO']);
        $independent = ['source' => 'global', 'reason_id' => $reason->id, 'version_id' => $version->id, 'adoption_id' => $adoption->id, 'adoption_version_id' => $selection->id];
        $this->postJson($this->url($order, $line), $this->body($order, $reference))->assertConflict();
        $this->postJson($this->url($order, $line), $this->body($order, $independent))->assertCreated();
        $this->assertDatabaseCount('clinical_exception_requests', 1);
        $this->assertDatabaseHas('clinical_exception_reason_references', ['reason_id' => $reason->id, 'version_id' => $version->id]);
    }

    #[DataProvider('versionConditions')]
    public function test_real_request_checks_missing_declaration_vigency_and_incidence_only_class(string $condition, string $expected): void
    {
        [$lab, $actor, $order, $line, $reference, $operator] = $this->prepared();
        $definition = GlobalClinicalReasonVersion::findOrFail($reference['version_id'])->only(['name', 'description', 'classification', 'evidence_requirements', 'is_active', 'change_reason']);
        if ($condition === 'incidence') {
            $definition['classification'] = 'incidence_only';
        }
        if ($condition === 'inactive') {
            $definition['is_active'] = false;
        }
        $version = app(ClinicalReasonVersionStore::class)->appendGlobal($reference['reason_id'], $operator, 1, $definition);
        $selection = ClinicalReasonAdoptionVersion::create(['laboratory_id' => $lab->id, 'reason_id' => $reference['reason_id'], 'adoption_id' => $reference['adoption_id'], 'version_id' => $version->id, 'revision' => 2, 'previous_id' => $reference['adoption_version_id'], 'created_by' => $actor->id, 'justification' => 'FICTICIO']);
        $reference['version_id'] = $version->id;
        $reference['adoption_version_id'] = $selection->id;
        if ($condition !== 'missing_declaration') {
            $this->impact($operator, $reference, 'non_restrictive', 0);
        }
        $this->postJson($this->url($order, $line), $this->body($order, $reference))->assertConflict()->assertJsonPath('code', $expected);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public static function versionConditions(): array
    {
        return [['missing_declaration', 'clinical_reason_use_blocked'], ['inactive', 'clinical_reason_use_blocked'], ['incidence', 'clinical_reason_not_terminal']];
    }

    #[DataProvider('immutableChanges')]
    public function test_sql_cannot_rewrite_dependency_or_mark_a_new_request_as_historical(string $operation): void
    {
        [$lab, $actor, $order, $line, $reference] = $this->prepared();
        $created = app(ClinicalResolutionService::class)->requestException($lab, $actor, $order->id, $line->id, $this->body($order, $reference));
        $before = ClinicalExceptionReasonReference::sole()->getRawOriginal();
        try {
            DB::transaction(function () use ($operation, $created): void {
                match ($operation) {
                    'update' => DB::table('clinical_exception_reason_references')->update(['justification' => 'FICTICIO: alterada']),
                    'delete' => DB::table('clinical_exception_reason_references')->delete(),
                    'legacy' => DB::table('clinical_exception_legacy_requests')->insert(['exception_request_id' => $created['exception']['id']]),
                };
            });
            $this->fail('SQL must preserve original clinical provenance.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame($before, ClinicalExceptionReasonReference::sole()->getRawOriginal());
        $this->assertDatabaseCount('clinical_exception_legacy_requests', 0);
    }

    public static function immutableChanges(): array
    {
        return [['update'], ['delete'], ['legacy']];
    }

    public function test_finalization_does_not_revoke_an_already_authorized_resolution_after_later_catalog_change(): void
    {
        [$lab, $actor, $order, $line, $reference, $operator, $approver] = $this->prepared();
        $service = app(ClinicalResolutionService::class);
        $created = $service->requestException($lab, $actor, $order->id, $line->id, $this->body($order, $reference));
        $service->decideException($lab, $approver, $order->id, $line->id, $created['exception']['id'], true, ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1]);
        $resolution = ClinicalLineResolution::sole()->getRawOriginal();
        $this->impact($operator, $reference, 'restrictive');

        $this->postJson("/api/v1/laboratory-orders/$order->id/finalize", ['clinical_revision' => $order->fresh()->clinical_revision])->assertOk();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($resolution, ClinicalLineResolution::sole()->getRawOriginal());
        $this->assertDatabaseCount('clinical_reason_impact_reviews', 1);
    }

    public function test_audit_failure_rolls_back_reference_request_and_revision(): void
    {
        [$lab, $actor, $order, $line, $reference] = $this->prepared();
        $revision = $order->fresh()->clinical_revision;
        $before = AuditLog::count();
        AuditLog::creating(function ($event): void {
            if ($event->event === 'order_clinical.exception_requested') {
                throw new \RuntimeException('FICTICIO: audit failure');
            }
        });
        try {
            app(ClinicalResolutionService::class)->requestException($lab, $actor, $order->id, $line->id, $this->body($order, $reference));
            $this->fail('Audit failure must abort.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FICTICIO: audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('clinical_exception_reason_references', 0);
        $this->assertSame($revision, $order->fresh()->clinical_revision);
        $this->assertSame($before, AuditLog::count());
    }

    public function test_migration_preserves_preexisting_text_requests_decisions_report_snapshots_and_pdf(): void
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        $approver = $this->createLaboratoryMember($lab);
        $integration = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $localIntegration = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $localIntegration->down();
        $integration->down();
        $request = ClinicalExceptionRequest::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id, 'reason' => 'FICTICIO: texto anterior irremplazable', 'requested_by' => $actor->id, 'requested_at' => now(), 'capture_revision' => 0, 'processing_started_at' => null, 'clinical_configuration_source' => $line->clinical_configuration_source]);
        $decision = ClinicalExceptionDecision::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id, 'exception_request_id' => $request->id, 'decision' => 'approved', 'reason' => null, 'decided_by' => $approver->id, 'decided_at' => now(), 'evidence_snapshot' => ['historical' => true]]);
        ClinicalLineResolution::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id, 'resolution_type' => 'authorized_exception', 'exception_decision_id' => $decision->id, 'resolved_by' => $approver->id, 'resolved_at' => now(), 'evidence_snapshot' => ['historical' => true]]);
        app(ClinicalResolutionService::class)->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        Storage::fake('clinical_reports');
        $report = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, ['type' => 'complete', 'clinical_revision' => $order->fresh()->clinical_revision, 'identity_confirmation' => true], 'ficticio-historical-report');
        $before = [$request->fresh()->getRawOriginal(), $decision->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal(), DB::scalar('SELECT report_line_snapshot(?)::text', [$line->id]), Storage::disk('clinical_reports')->get($report->private_path)];

        DB::transaction(function () use ($integration, $localIntegration): void { $integration->up(); $localIntegration->up(); });

        $this->assertSame($before, [$request->fresh()->getRawOriginal(), $decision->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal(), DB::scalar('SELECT report_line_snapshot(?)::text', [$line->id]), Storage::disk('clinical_reports')->get($report->private_path)]);
        $this->getJson($this->url($order, $line))->assertOk()->assertJsonPath('data.0.reason_reference', null);
        $this->assertDatabaseCount('clinical_exception_reason_references', 0);
        $this->assertDatabaseHas('clinical_exception_legacy_requests', ['exception_request_id' => $request->id]);
    }

    public function test_preexisting_pending_request_can_be_decided_without_inventing_a_catalog_link(): void
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        $approver = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($approver, $lab, ['orders.clinical_exceptions.approve']);
        $integration = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $localIntegration = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $localIntegration->down();
        $integration->down();
        $request = ClinicalExceptionRequest::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id, 'reason' => 'FICTICIO: anterior', 'requested_by' => $actor->id, 'requested_at' => now(), 'capture_revision' => 0, 'processing_started_at' => null, 'clinical_configuration_source' => $line->clinical_configuration_source]);
        DB::transaction(function () use ($integration, $localIntegration): void { $integration->up(); $localIntegration->up(); });
        $this->actingAs($approver)->postJson($this->url($order, $line).'/'.$request->id.'/approve', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertOk()->assertJsonPath('data.reason_reference', null);
        $this->assertDatabaseCount('clinical_line_resolutions', 1);
        $this->assertSame('FICTICIO: anterior', $request->fresh()->reason);
        $this->assertDatabaseCount('clinical_exception_reason_references', 0);
    }

    public function test_openapi_requires_structured_reference_and_separate_justification_on_the_existing_route(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $input = $document['components']['schemas']['ClinicalExceptionInput'];
        $this->assertSame(['clinical_revision', 'result_revision', 'reason_reference', 'justification'], $input['required']);
        $this->assertFalse($input['additionalProperties']);
        $this->assertArrayNotHasKey('reason', $input['properties']);
        $this->assertSame(['global'], $document['components']['schemas']['ClinicalExceptionGlobalReasonInput']['properties']['source']['enum']);
        $this->assertSame('#/components/schemas/ClinicalExceptionInput', $document['paths']['/api/v1/laboratory-orders/{order}/exams/{line}/exceptions']['post']['requestBody']['content']['application/json']['schema']['$ref']);
    }

    public function test_sql_cannot_commit_a_new_text_request_or_extend_the_sealed_historical_set(): void
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        DB::beginTransaction();
        try {
            ClinicalExceptionRequest::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id, 'reason' => 'FICTICIO: bypass SQL', 'requested_by' => $actor->id, 'requested_at' => now(), 'capture_revision' => 0, 'processing_started_at' => null, 'clinical_configuration_source' => $line->clinical_configuration_source]);
            DB::statement('SET CONSTRAINTS require_exception_reason_reference IMMEDIATE');
            $this->fail('New SQL requests need a structured dependency.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }
}
