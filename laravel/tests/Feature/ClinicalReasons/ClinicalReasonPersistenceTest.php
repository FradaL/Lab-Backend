<?php

namespace Tests\Feature\ClinicalReasons;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\Models\GlobalClinicalReason;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReason;
use App\Models\LaboratoryClinicalReasonVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalReasonPersistenceTest extends TestCase
{
    use ClinicalReasonScenario, RefreshDatabase;

    #[DataProvider('scopes')]
    public function test_stable_identity_preserves_versions_and_clinical_tables(string $scope): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario($scope);
        $append = fn ($expected, $definition) => $scope === 'global'
            ? app(ClinicalReasonVersionStore::class)->appendGlobal($reason->id, $actor, $expected, $definition)
            : app(ClinicalReasonVersionStore::class)->appendLocal($lab, $reason->id, $actor, $expected, $definition);
        $first = $append(0, $this->definition());
        $before = $first->getRawOriginal();
        $identity = $reason->fresh()->getRawOriginal();
        $second = $append(1, $this->definition(['name' => 'FICTICIO: nombre posterior', 'classification' => 'terminal_candidate', 'is_active' => false, 'change_reason' => 'FICTICIO: revisión documentada']));
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        $this->assertSame($identity, $reason->fresh()->getRawOriginal());
        $this->assertSame([1, 2], $reason->versions()->orderBy('number')->pluck('number')->all());
        $this->assertSame($second->id, $reason->fresh()->latestVersion->id);
        $this->assertSame($reason->id, $second->reason->id);
        $this->assertSame($actor->id, $second->createdBy->id);
        $this->assertNotNull($first->created_at);
        $this->assertFalse($second->is_active);
        $this->assertEquals($this->definition()['evidence_requirements'], $first->evidence_requirements);
        foreach (['clinical_exception_requests', 'clinical_exception_decisions', 'clinical_line_resolutions', 'clinical_order_finalizations', 'laboratory_report_issuances'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function scopes(): array
    {
        return [['global'], ['laboratory']];
    }

    public function test_code_uniqueness_is_scoped_and_identity_cannot_be_renamed(): void
    {
        [$lab, $actor, $local] = $this->reasonScenario('laboratory');
        $other = Laboratory::factory()->create();
        $otherActor = $this->createLaboratoryMember($other);
        LaboratoryClinicalReason::create(['laboratory_id' => $other->id, 'code' => $local->code, 'created_by' => $otherActor->id]);
        GlobalClinicalReason::create(['code' => $local->code, 'created_by' => $actor->id]);
        $this->assertSqlRejected(fn () => DB::table('laboratory_clinical_reasons')->insert(['laboratory_id' => $lab->id, 'code' => $local->code, 'created_by' => $actor->id]), ['23505']);
        $this->assertSqlRejected(fn () => DB::table('global_clinical_reasons')->insert(['code' => $local->code, 'created_by' => $actor->id]), ['23505']);
        $this->assertSqlRejected(fn () => DB::table('global_clinical_reasons')->insert(['code' => 'INVALID CODE', 'created_by' => $actor->id]));
    }

    #[DataProvider('scopes')]
    public function test_sql_rejects_duplicate_and_skipped_versions(string $scope): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario($scope);
        $row = $this->sqlDefinition($reason->id, $actor->id) + ($scope === 'laboratory' ? ['laboratory_id' => $lab->id] : []);
        DB::table($scope.'_clinical_reason_versions')->insert($row);
        $this->assertSqlRejected(fn () => DB::table($scope.'_clinical_reason_versions')->insert($row), ['23514', '23505']);
        $this->assertSqlRejected(fn () => DB::table($scope.'_clinical_reason_versions')->insert(array_replace($row, ['number' => 3])));
        $this->assertSame(1, $reason->versions()->count());
    }

    #[DataProvider('immutableOperations')]
    public function test_raw_sql_cannot_mutate_identity_or_version(string $scope, bool $version, string $operation): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario($scope);
        $row = $this->sqlDefinition($reason->id, $actor->id) + ($scope === 'laboratory' ? ['laboratory_id' => $lab->id] : []);
        $versionId = DB::table($scope.'_clinical_reason_versions')->insertGetId($row);
        $table = $scope.($version ? '_clinical_reason_versions' : '_clinical_reasons');
        $id = $version ? $versionId : $reason->id;
        $before = DB::table($table)->where('id', $id)->first();
        $this->assertSqlRejected(fn () => $operation === 'delete'
            ? DB::table($table)->where('id', $id)->delete()
            : DB::table($table)->where('id', $id)->update($version ? ['name' => 'Altered'] : ['code' => 'altered']));
        $this->assertEquals($before, DB::table($table)->where('id', $id)->first());
    }

    public static function immutableOperations(): array
    {
        $cases = [];
        foreach (['global', 'laboratory'] as $scope) {
            foreach ([false, true] as $version) {
                foreach (['update', 'delete'] as $operation) {
                    $cases[] = [$scope, $version, $operation];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('invalidDefinitions')]
    public function test_sql_validates_definition_and_evidence_structure(string $scope, array $change): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario($scope);
        $row = $this->sqlDefinition($reason->id, $actor->id) + ($scope === 'laboratory' ? ['laboratory_id' => $lab->id] : []);
        $this->assertSqlRejected(fn () => DB::table($scope.'_clinical_reason_versions')->insert(array_replace($row, $change)));
    }

    public static function invalidDefinitions(): array
    {
        $cases = [];
        foreach (['global', 'laboratory'] as $scope) {
            foreach ([['classification' => 'auto_approve'], ['number' => 0], ['name' => ' '], ['description' => ''], ['change_reason' => ' '], ['evidence_requirements' => '[]'], ['evidence_requirements' => '{"schema_version":1,"requirements":["a","a"]}'], ['evidence_requirements' => '{"schema_version":1,"requirements":[false]}'], ['evidence_requirements' => '{"schema_version":1,"requirements":[],"unknown":true}']] as $change) {
                $cases[] = [$scope, $change];
            }
        }

        return $cases;
    }

    public function test_foreign_tenant_parent_and_actor_are_rejected_by_database(): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario('laboratory');
        $other = Laboratory::factory()->create();
        $otherActor = $this->createLaboratoryMember($other);
        $table = 'laboratory_clinical_reason_versions';
        $row = $this->sqlDefinition($reason->id, $actor->id) + ['laboratory_id' => $lab->id];
        $this->assertSqlRejected(fn () => DB::table($table)->insert(array_replace($row, ['laboratory_id' => $other->id, 'created_by' => $otherActor->id])), ['23503']);
        $this->assertSqlRejected(fn () => DB::table($table)->insert(array_replace($row, ['created_by' => $otherActor->id])), ['23503']);
        $this->assertSqlRejected(fn () => DB::table($table)->insert(array_replace($row, ['reason_id' => 999999])), ['23503']);
        $this->assertSqlRejected(fn () => DB::table('laboratory_clinical_reasons')->insert(['laboratory_id' => $lab->id, 'code' => 'foreign_actor', 'created_by' => $otherActor->id]), ['23503']);
        $this->assertSame(0, LaboratoryClinicalReason::forLaboratory($other)->count());
        $this->assertSame(0, LaboratoryClinicalReasonVersion::forLaboratory($other)->count());
        $this->expectException(ModelNotFoundException::class);
        app(ClinicalReasonVersionStore::class)->appendLocal($other, $reason->id, $otherActor, 0, $this->definition());
    }

    public function test_stale_expected_version_does_not_write(): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario();
        app(ClinicalReasonVersionStore::class)->appendGlobal($reason->id, $actor, 0, $this->definition());
        try {
            app(ClinicalReasonVersionStore::class)->appendGlobal($reason->id, $actor, 0, $this->definition());
            $this->fail('Stale version should fail.');
        } catch (ClinicalConflict $e) {
            $this->assertSame('clinical_reason_version_conflict', $e->errorCode);
        }
        $this->assertSame(1, $reason->versions()->count());
    }

    public function test_global_versions_require_real_identity_and_actor(): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario();
        $this->assertSqlRejected(fn () => DB::table('global_clinical_reason_versions')->insert($this->sqlDefinition(999999, $actor->id)), ['23503']);
        $this->assertSqlRejected(fn () => DB::table('global_clinical_reason_versions')->insert($this->sqlDefinition($reason->id, 999999)), ['23503']);
        $this->assertSame(0, $reason->versions()->count());
    }

    public function test_unused_migration_can_roll_back_and_reapply_without_removing_be08(): void
    {
        $governance = require database_path('migrations/2026_10_25_073106_extend_saas_audit_for_global_clinical_reasons.php');
        $governance->down();
        $migration = require database_path('migrations/2026_10_25_062239_create_clinical_reason_identities_and_versions.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('global_clinical_reasons'));
        $this->assertFalse(Schema::hasTable('laboratory_clinical_reason_versions'));
        $this->assertTrue(Schema::hasTable('clinical_exception_requests'));
        $this->assertTrue(Schema::hasTable('clinical_line_resolutions'));
        $migration->up();
        $governance->up();
        $this->assertDatabaseCount('global_clinical_reasons', 0);
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 0);
    }

    public function test_store_rejects_forged_actor_or_unexpected_fields(): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario();
        $this->expectException(ValidationException::class);
        app(ClinicalReasonVersionStore::class)->appendGlobal($reason->id, $actor, 0, $this->definition(['created_by' => 99]));
    }

    public function test_models_reject_mutation_before_sql(): void
    {
        [$lab, $actor, $reason] = $this->reasonScenario();
        $this->expectException(\LogicException::class);
        $reason->update(['code' => 'changed']);
    }

    public function test_rollback_refuses_existing_reason_history(): void
    {
        $this->reasonScenario();
        $migration = require database_path('migrations/2026_10_25_062239_create_clinical_reason_identities_and_versions.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function sqlDefinition(int $reasonId, int $actorId): array
    {
        $definition = $this->definition();
        $definition['evidence_requirements'] = json_encode($definition['evidence_requirements'], JSON_THROW_ON_ERROR);

        return $definition + ['reason_id' => $reasonId, 'created_by' => $actorId, 'number' => 1];
    }

    private function assertSqlRejected(\Closure $operation, array $states = ['23514']): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL must reject this write.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], $states, $e->getMessage());
        }
    }
}
