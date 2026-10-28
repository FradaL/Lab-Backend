<?php

namespace Tests\Feature\ClinicalReasons;

use App\Authorization\SaasAccessAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\ClinicalReasonEligibilityPolicy;
use App\ClinicalExams\GlobalClinicalReasonImpactManager;
use App\ClinicalExams\GlobalClinicalReasonManager;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonImpactReview;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class ClinicalReasonAdoptionConcurrencyTest extends TestCase
{
    use ClinicalReasonAdoptionScenario, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('races')]
    public function test_independent_connections_preserve_adoption_and_review_obligations(string $case, string $expected): void
    {
        [$operator,$admin] = $this->operator();
        $global = $this->globalReason($operator);
        $this->declareImpact($operator, $global);
        $target = app(GlobalClinicalReasonManager::class)->publish($operator, $global['reason']->id, ['expected_number' => 1, 'definition' => $this->definition()])['data'];
        [$lab,$owner] = $this->tenant();
        $reviewer = $this->reviewer($lab);
        $adoption = null;
        if (! in_array($case, ['adopt', 'adopt_before_impact', 'impact_before_adopt'], true)) {
            $adoption = $this->selectReason($lab, $owner, $global)['adoption'];
        }
        if (in_array($case, ['resolve', 'resolve_before_impact', 'resolve_before_publish'], true)) {
            $this->declareImpact($operator, $target, 'restrictive');
        }
        $review = ClinicalReasonImpactReview::first();
        $classifyGrant = DB::table('saas_capability_assignments')->where('user_id', $operator->id)->where('capability_code', GlobalClinicalReasonImpactManager::CAPABILITY)->first();
        $operation = function (bool $writer) use ($case, $operator, $admin, $global, $target, $lab, $owner, $reviewer, $adoption, $review, $classifyGrant): void {
            $this->setPermissionTeam($lab);
            $adopt = fn () => $this->selectReason($lab, $owner, $global);
            $impact = fn (int $expected = 0, string $classification = 'restrictive') => $this->declareImpact($operator, $target, $classification, $expected);
            $resolve = fn () => app(ClinicalReasonAdoptionManager::class)->resolve($lab, $reviewer, $adoption->id, $review->id, ['expected_revision' => 0, 'outcome' => 'favorable', 'assessment' => 'FICTICIO: evaluación clínica completa.', 'justification' => 'FICTICIO']);
            match ($case) {
                'impact' => $impact(0, 'non_restrictive'),
                'adopt' => $adopt(),
                'adopt_before_impact' => $writer ? $adopt() : $impact(),
                'impact_before_adopt' => $writer ? $impact() : $adopt(),
                'update_before_impact' => $writer ? app(ClinicalReasonAdoptionManager::class)->update($lab, $owner, $adoption->id, ['expected_revision' => 1, 'version_id' => $target['version']->id, 'justification' => 'FICTICIO']) : $impact(),
                'resolve' => $resolve(),
                'resolve_before_impact' => $writer ? $resolve() : $impact(1),
                'revocation_before_impact' => $writer ? app(SaasAccessManager::class)->revoke($admin, $operator->id, $classifyGrant->id, ['revision' => 1, 'reason' => 'FICTICIO']) : $impact(),
                'resolve_before_publish' => $writer ? $resolve() : app(GlobalClinicalReasonManager::class)->publish($operator, $global['reason']->id, ['expected_number' => 2, 'definition' => $this->definition()]),
            };
        };
        $dir = sys_get_temp_dir().'/adoption-review-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($dir.'/pid', (string) DB::scalar('SELECT pg_backend_pid()'));
                $deadline = microtime(true) + 15;
                while (! file_exists($dir.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($dir.'/go')) {
                    throw new \RuntimeException('Missing writer signal.');
                }
                $operation(false);
                $result = 'ok';
            } catch (ClinicalConflict $e) {
                $result = $e->errorCode;
            } catch (HttpExceptionInterface $e) {
                $result = (string) $e->getStatusCode();
            } catch (QueryException $e) {
                $result = $e->errorInfo[0];
            } catch (Throwable $e) {
                $result = $e::class.': '.$e->getMessage();
            }
            file_put_contents($dir.'/result', $result);
            DB::disconnect();
            exit(0);
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            app(SaasAccessAuthority::class)->lock();
            $operation(true);
            touch($dir.'/go');
            $deadline = microtime(true) + 15;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($dir.'/pid')) {
                    $blocked = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock')", [(int) file_get_contents($dir.'/pid')]);
                    if ($blocked || file_exists($dir.'/result')) {
                        break;
                    }
                }
                usleep(1000);
            }
            $this->assertTrue($blocked, 'The second PostgreSQL connection must really wait.');
            DB::commit();
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame($expected, file_get_contents($dir.'/result'));
            $this->setPermissionTeam($lab);
            $selected = ClinicalReasonAdoption::sole();
            $this->assertDatabaseCount('clinical_reason_adoptions', 1);
            $reviewCount = match ($case) {
                'impact','adopt','revocation_before_impact' => 0,'resolve_before_impact' => 2,default => 1
            };
            $this->assertDatabaseCount('clinical_reason_impact_reviews', $reviewCount);
            $this->assertDatabaseCount('clinical_reason_adoption_versions', $case === 'update_before_impact' ? 2 : 1);
            $this->assertSame($case === 'update_before_impact' ? $target['version']->id : $global['version']->id, $selected->latestVersion->version_id);
            $eligibility = app(ClinicalReasonEligibilityPolicy::class)->eligibility($lab, $owner, $selected->id);
            if (in_array($case, ['adopt_before_impact', 'impact_before_adopt', 'update_before_impact', 'resolve_before_impact'], true)) {
                $this->assertFalse($eligibility['can_approve']);
                $this->assertNotEmpty($eligibility['pending_review_ids']);
            }
            if ($case === 'resolve_before_publish') {
                $this->assertFalse($eligibility['can_approve']);
                $this->assertSame([], $eligibility['pending_review_ids']);
                $this->assertContains('impact_unclassified', $eligibility['approval_blockers']);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            touch($dir.'/go');
            pcntl_waitpid($pid, $status);
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public static function races(): array
    {
        return [['impact', 'clinical_reason_impact_revision_conflict'], ['adopt', 'clinical_reason_already_adopted'], ['adopt_before_impact', 'ok'], ['impact_before_adopt', 'ok'], ['update_before_impact', 'ok'], ['resolve', 'clinical_reason_review_revision_conflict'], ['resolve_before_impact', 'ok'], ['revocation_before_impact', '403'], ['resolve_before_publish', 'ok']];
    }
}
