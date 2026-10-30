<?php

namespace Tests\Feature\ClinicalFinalization;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalExceptionEvidenceGuard;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\Models\ClinicalExceptionRequest;
use App\Models\Laboratory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClinicalTransversalIntegrityTest extends TestCase
{
    use ClinicalExceptionScenario, RefreshDatabase;

    public static function auditMutations(): array
    {
        $cases = [];
        foreach (['clinical_replacement.recorded', 'clinical_reason.adopted', 'clinical_reason.local_review_evaluated', 'order_clinical.exception_requested', 'order_results.saved', 'result_validation.validated', 'order_report.issued'] as $event) {
            foreach (['update', 'delete', 'rename'] as $operation) {
                $cases[$event.' '.$operation] = [$event, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('auditMutations')]
    public function test_direct_sql_cannot_rewrite_clinical_audit(string $event, string $operation): void
    {
        $lab = Laboratory::factory()->create();
        $log = app(AuditWriter::class)->record($lab, null, new AuditEvent($event));
        $original = (array) DB::table('audit_logs')->find($log->id);
        try {
            DB::transaction(function () use ($log, $operation): void {
                $query = DB::table('audit_logs')->where('id', $log->id);
                $operation === 'delete' ? $query->delete() : $query->update($operation === 'rename' ? ['event' => 'unrelated.synthetic'] : ['metadata' => '{"synthetic":true}']);
            });
            $this->fail('PostgreSQL must reject direct mutation of clinical audit.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame($original, (array) DB::table('audit_logs')->find($log->id));
    }

    #[DataProvider('unrelatedEvents')]
    public function test_unrelated_audit_is_not_changed_by_clinical_protection(string $event): void
    {
        $lab = Laboratory::factory()->create();
        $log = app(AuditWriter::class)->record($lab, null, new AuditEvent($event));
        $this->assertSame(1, DB::table('audit_logs')->where('id', $log->id)->update(['metadata' => '{}']));
        $this->assertSame(1, DB::table('audit_logs')->where('id', $log->id)->delete());
    }

    public static function unrelatedEvents(): array
    {
        return [['unrelated.synthetic'], ['clinicalxreason.synthetic'], ['clinicalxreplacement.synthetic']];
    }

    public static function subscriptions(): array
    {
        $cases = [];
        foreach (['missing', 'future', 'expired', 'valid'] as $state) {
            foreach (['request', 'approve', 'reject', 'review', 'line', 'order', 'show', 'exceptions'] as $command) {
                $cases[$state.' '.$command] = [$state, $command];
            }
        }

        return $cases;
    }

    #[DataProvider('subscriptions')]
    public function test_internal_commands_check_subscription(string $state, string $command): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $service = app(ClinicalResolutionService::class);
        $request = in_array($command, ['approve', 'reject', 'review', 'order', 'exceptions'], true) ? $this->request($s) : null;
        if ($command === 'order') {
            $service->decideException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $request, true, ['clinical_revision' => $s['order']->fresh()->clinical_revision, 'request_revision' => 1]);
        }
        $subscriptions = $s['lab']->subscriptions();
        match ($state) {
            'missing' => $subscriptions->delete(),
            'future' => $subscriptions->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addMonth()]),
            'expired' => $subscriptions->update(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]),
            default => null,
        };
        $revision = $s['order']->fresh()->clinical_revision;
        $before = DB::table('audit_logs')->count();
        try {
            $result = match ($command) {
                'request' => $service->requestException($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, $this->requestBody($s)),
                'approve', 'reject' => $service->decideException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $request, $command === 'approve', ['clinical_revision' => $revision, 'request_revision' => 1, 'reason' => 'FICTICIO: decisión']),
                'review' => $service->reviewException($s['lab'], $s['approver'], $s['order']->id, $s['line']->id, $request, ['clinical_revision' => $revision, 'request_revision' => 1, 'dependency_token' => hash('sha256', DB::scalar('SELECT clinical_exception_dependencies(?)::text', [$s['line']->id])), 'justification' => 'FICTICIO: revisión']),
                'show' => $service->show($s['lab'], $s['actor'], $s['order']->id),
                'exceptions' => $service->exceptions($s['lab'], $s['actor'], $s['order']->id, $s['line']->id),
                'line' => $service->finalizeLine($s['lab'], $s['actor'], $s['order']->id, $s['line']->id, ['clinical_revision' => $revision, 'result_revision' => 0]),
                'order' => $service->finalizeOrder($s['lab'], $s['actor'], $s['order']->id, ['clinical_revision' => $revision]),
            };
            $this->assertSame('valid', $state, 'Internal command accepted an inaccessible subscription.');
            $this->assertNotEmpty($result);
        } catch (HttpException $e) {
            $this->assertNotSame('valid', $state);
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame($before, DB::table('audit_logs')->count());
            $this->assertSame($revision, $s['order']->fresh()->clinical_revision);
        } catch (ClinicalConflict $e) {
            // An accessible subscription must still enforce normal clinical prerequisites.
            $this->assertSame('valid', $state);
            $this->assertSame('line', $command);
            $this->assertSame('clinical_line_not_validated', $e->errorCode);
        }
    }

    public function test_additive_migration_preserves_existing_rows_and_does_not_duplicate_triggers(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $this->request($s);
        $before = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $reviews = DB::table('clinical_exception_evidence_reviews')->orderBy('id')->get()->toJson();
        $triggers = DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid='audit_logs'::regclass AND NOT tgisinternal");
        $migration = require database_path('migrations/2026_10_29_180000_harden_clinical_transversal_guarantees.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('audit_logs')->orderBy('id')->get()->toJson());
        $this->assertSame($reviews, DB::table('clinical_exception_evidence_reviews')->orderBy('id')->get()->toJson());
        $this->assertSame($triggers, DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid='audit_logs'::regclass AND NOT tgisinternal"));
        $log = DB::table('audit_logs')->where('event', 'clinical_replacement.recorded')->first();
        try {
            DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->delete());
            $this->fail('The restored guard must protect existing history.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_evidence_guard_cannot_be_used_as_an_autonomous_writer(): void
    {
        $s = $this->prepared();
        $this->evidence($s, 'patient_refusal');
        $id = $this->request($s);
        $before = DB::table('clinical_exception_evidence_reviews')->count();
        try {
            app(ClinicalExceptionEvidenceGuard::class)->remember($s['lab'], ClinicalExceptionRequest::findOrFail($id), $s['actor']->id, 'FICTICIO: bypass', 'review');
            $this->fail('Evidence persistence must only be reachable through the clinical command.');
        } catch (\Error $e) {
            $this->assertStringContainsString('remember', $e->getMessage());
        }
        $this->assertSame($before, DB::table('clinical_exception_evidence_reviews')->count());
    }
}
