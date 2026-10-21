<?php

namespace Tests\Feature\ClinicalReports;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReports\ClinicalReportRenderer;
use App\ClinicalReports\ClinicalReportService;
use App\Models\LaboratoryReportIssuance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ClinicalReportConcurrencyTest extends TestCase
{
    use ClinicalReportScenario, DatabaseMigrations {
        ClinicalReportScenario::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    #[DataProvider('parallelKeys')]
    public function test_parallel_issuances_use_real_connections_and_operation_mutex(string $mode): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $dir = sys_get_temp_dir().'/be091-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $body = $this->body($order, $line);
        $this->assertSame('pgsql', DB::getDriverName());
        DB::disconnect();
        $pids = [];
        foreach ([1, 2] as $index) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    file_put_contents($dir.'/pid-'.$index, (string) DB::scalar('SELECT pg_backend_pid()'));
                    if ($index === 2) {
                        $this->awaitFile($dir.'/ready-1');
                    }
                    $renderer = new class($dir, $index) extends ClinicalReportRenderer
                    {
                        public function __construct(private string $dir, private int $index) {}

                        public function render(array $document, string $verificationUrl): string
                        {
                            if (DB::transactionLevel() !== 0) {
                                throw new \LogicException('Rendering held a transaction.');
                            }
                            file_put_contents($this->dir.'/ready-'.$this->index, '1');
                            $deadline = microtime(true) + 20;
                            while (! file_exists($this->dir.'/go') && microtime(true) < $deadline) {
                                usleep(1000);
                            }
                            if (! file_exists($this->dir.'/go')) {
                                throw new \RuntimeException('Race barrier timeout');
                            }

                            return parent::render($document, $verificationUrl);
                        }
                    };
                    app()->instance(ClinicalReportRenderer::class, $renderer);
                    $input = $body;
                    if ($mode === 'changed_payload' && $index === 2) {
                        $input['identity_confirmation'] = false;
                    }
                    $key = $mode === 'different_keys' ? 'parallel-report-key-'.$index : 'parallel-report-same-key';
                    $r = app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $input, $key);
                    file_put_contents($dir.'/out-'.$index, json_encode(['status' => 201, 'id' => $r->id]));
                } catch (\Throwable $e) {
                    file_put_contents($dir.'/out-'.$index, json_encode(['status' => $e instanceof ClinicalConflict ? $e->status : 500, 'code' => $e instanceof ClinicalConflict ? $e->errorCode : get_class($e)]));
                }
                DB::disconnect();
                exit(0);
            }
            $pids[] = $pid;
        }
        DB::reconnect();
        $this->awaitFile($dir.'/ready-1');
        $this->awaitFile($dir.'/pid-2');
        if ($mode === 'different_keys') {
            $this->awaitFile($dir.'/ready-2');
            $backendIds = [(int) file_get_contents($dir.'/pid-1'), (int) file_get_contents($dir.'/pid-2')];
            $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM pg_stat_activity WHERE pid IN (?,?) AND xact_start IS NOT NULL', $backendIds));
        } else {
            $backend = (int) file_get_contents($dir.'/pid-2');
            $deadline = microtime(true) + 15;
            do {
                $waiting = DB::scalar('SELECT wait_event FROM pg_stat_activity WHERE pid=?', [$backend]);
                if ($waiting === 'advisory') {
                    break;
                } usleep(1000);
            } while (microtime(true) < $deadline);
            $this->assertSame('advisory', $waiting);
        }
        file_put_contents($dir.'/go', '1');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $a = json_decode(file_get_contents($dir.'/out-1'), true);
        $b = json_decode(file_get_contents($dir.'/out-2'), true);
        $this->assertSame(201, $a['status'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertSame($mode === 'changed_payload' ? 409 : 201, $b['status'], json_encode($b, JSON_THROW_ON_ERROR));
        $count = $mode === 'different_keys' ? 2 : 1;
        $this->assertDatabaseCount('laboratory_report_issuances', $count);
        $this->assertCount($count, Storage::disk('clinical_reports')->allFiles());
        if ($mode === 'same_key') {
            $this->assertSame($a['id'], $b['id']);
        }
        if ($mode === 'different_keys') {
            $this->assertSame([1, 2], LaboratoryReportIssuance::orderBy('sequence')->pluck('sequence')->all());
        }
        foreach (LaboratoryReportIssuance::all() as $report) {
            $this->assertSame($report->pdf_hash, hash('sha256', Storage::disk('clinical_reports')->get($report->private_path)));
        }
    }

    public static function parallelKeys(): array
    {
        return [['same_key'], ['different_keys'], ['changed_payload']];
    }

    #[DataProvider('clinicalChanges')]
    public function test_changes_committed_during_render_are_rechecked_and_file_is_compensated(string $change, int $expected): void
    {
        [$lab,$actor,$order,$line] = $this->resolved();
        $dir = sys_get_temp_dir().'/be091-change-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $body = $this->body($order, $line);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($dir.'/pid', (string) DB::scalar('SELECT pg_backend_pid()'));
                $renderer = new class($dir) extends ClinicalReportRenderer
                {
                    public function __construct(private string $dir) {}

                    public function render(array $document, string $verificationUrl): string
                    {
                        file_put_contents($this->dir.'/ready', '1');
                        $deadline = microtime(true) + 20;
                        while (! file_exists($this->dir.'/go') && microtime(true) < $deadline) {
                            usleep(1000);
                        }
                        if (! file_exists($this->dir.'/go')) {
                            throw new \RuntimeException('Race barrier timeout');
                        }

                        return parent::render($document, $verificationUrl);
                    }
                };
                app()->instance(ClinicalReportRenderer::class, $renderer);
                app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $body, 'changed-during-render-key');
                file_put_contents($dir.'/out', json_encode(['status' => 201]));
            } catch (\Throwable $e) {
                $status = $e instanceof ClinicalConflict ? $e->status : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e instanceof AuthorizationException ? 403 : 500));
                file_put_contents($dir.'/out', json_encode(['status' => $status, 'kind' => get_class($e)]));
            }
            DB::disconnect();
            exit(0);
        }
        DB::reconnect();
        $this->awaitFile($dir.'/ready');
        $backend = (int) file_get_contents($dir.'/pid');
        $this->assertNull(DB::scalar('SELECT xact_start FROM pg_stat_activity WHERE pid=?', [$backend]));
        match ($change) {
            'closure' => app(ClinicalResolutionService::class)->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]),
            'permission' => DB::table('model_has_permissions')->where('laboratory_id', $lab->id)->where('model_id', $actor->id)->where('permission_id', DB::table('permissions')->where('name', 'reports.issue')->value('id'))->delete(),
            'membership' => DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $actor->id)->update(['is_active' => false]),
            'subscription' => DB::table('subscriptions')->where('laboratory_id', $lab->id)->update(['status' => 'inactive']),
            'identity' => $order->patient->update(['first_names' => 'Changed during render']),
        };
        file_put_contents($dir.'/go', '1');
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame($expected, json_decode(file_get_contents($dir.'/out'), true)['status']);
        $this->assertDatabaseCount('laboratory_report_issuances', 0);
        $this->assertCount(0, Storage::disk('clinical_reports')->allFiles());
    }

    public static function clinicalChanges(): array
    {
        return [['closure', 409], ['permission', 403], ['membership', 403], ['subscription', 403], ['identity', 409]];
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 25;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertFileExists($path);
    }
}
