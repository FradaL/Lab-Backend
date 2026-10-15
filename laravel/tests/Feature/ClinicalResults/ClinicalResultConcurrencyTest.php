<?php

namespace Tests\Feature\ClinicalResults;

use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalResults\LaboratoryResultService;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class ClinicalResultConcurrencyTest extends TestCase
{
    use ClinicalResultScenario;
    use DatabaseMigrations;

    #[DataProvider('races')]
    public function test_independent_connections_preserve_revision_state_and_fixed_version(string $writer, bool $rollback, string $expected): void
    {
        [$lab, $actor, $order, $line] = $this->scenario();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        setPermissionsTeamId($lab->id);
        $revision = $writer === 'edit' ? 1 : 0;
        if ($writer === 'edit') {
            app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id,
                ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '0']]]);
        }
        $directory = sys_get_temp_dir().'/be06-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($directory.'/pid', (string) DB::scalar('SELECT pg_backend_pid()'));
                $deadline = microtime(true) + 15;
                while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($directory.'/go')) {
                    throw new \RuntimeException('Writer never signaled capture.');
                }
                app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id,
                    ['revision' => $revision, 'results' => [['parameter_code' => 'N', 'value' => '2']]]);
                $result = ['status' => 'ok'];
            } catch (ClinicalConflict $exception) {
                $result = ['status' => $exception->errorCode];
            } catch (HttpExceptionInterface $exception) {
                $result = ['status' => $exception->getStatusCode() === 403 ? 'forbidden' : 'http_error'];
            } catch (Throwable $exception) {
                $result = ['status' => 'error', 'message' => $exception->getMessage()];
            }
            file_put_contents($directory.'/result', json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            exit(0);
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            if ($writer === 'deactivate_actor') {
                LaboratoryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $actor->update(['is_active' => false]);
            } elseif ($writer === 'cancel') {
                app(TransitionLaboratoryOrderStatus::class)->execute($lab, $order->id, 'cancelled', $actor);
            } elseif ($writer === 'publish') {
                // Publication need not lock this historical line: capture must continue using its original version.
                $clinical = app(ClinicalExamService::class);
                $draft = $clinical->fork($lab, $actor, $line->laboratory_exam_id, $line->clinical_version_id);
                $configuration = $draft->configuration;
                $configuration['parameters'][0]['unit'] = 'NEW-DEMO-U';
                $draft = $clinical->update($lab, $actor, $line->laboratory_exam_id, $draft->id, 1, $configuration);
                $clinical->publish($lab, $actor, $line->laboratory_exam_id, $draft->id, $draft->revision);
            } else {
                app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id,
                    ['revision' => $revision, 'results' => [['parameter_code' => $writer === 'save_other' ? 'T' : 'N', 'value' => $writer === 'save_other' ? 'writer' : '1']]]);
            }
            touch($directory.'/go');
            $deadline = microtime(true) + 15;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($directory.'/pid')) {
                    $backend = (int) file_get_contents($directory.'/pid');
                    $blocked = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock')", [$backend]);
                    if ($blocked || file_exists($directory.'/result')) {
                        break;
                    }
                }
                usleep(1000);
            }
            if ($writer === 'publish') {
                $this->assertFileExists($directory.'/result', 'Historical capture proceeds independently of publication.');
            } else {
                $this->assertTrue($blocked, 'The independent capture connection must actually wait on the order lock.');
            }
            $rollback ? DB::rollBack() : DB::commit();
            pcntl_waitpid($pid, $exit);
            $this->assertSame(0, pcntl_wexitstatus($exit));
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $result['status'], $result['message'] ?? '');
            $this->assertDatabaseCount('laboratory_order_results', in_array($writer, ['cancel', 'deactivate_actor'], true) && ! $rollback ? 0 : 1);
            if ($expected === 'result_revision_conflict') {
                $row = LaboratoryOrderResult::sole();
                $this->assertSame($writer === 'save_other' ? 'writer' : '1', $writer === 'save_other' ? $row->text_value : $row->numeric_value);
                $this->assertSame($revision + 1, $row->capture_revision);
            }
            if ($expected === 'ok') {
                $row = LaboratoryOrderResult::sole();
                $this->assertSame('2', $row->numeric_value);
                $this->assertSame($line->clinical_version_id, $row->clinical_version_id);
                $this->assertSame('DEMO-U', $row->unit);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            touch($directory.'/go');
            pcntl_waitpid($pid, $exit);
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::table('laboratory_orders')->update(['status' => 'pending']);
            $this->clearPermissionTeam();
        }
    }

    public static function races(): array
    {
        return ['actor deactivated while waiting' => ['deactivate_actor', false, 'forbidden'],
            'existing parameter concurrent edit' => ['edit', false, 'result_revision_conflict'],
            'different parameters still share the line revision' => ['save_other', false, 'result_revision_conflict'],
            'same parameter and duplicate first capture' => ['save', false, 'result_revision_conflict'],
            'capture rollback' => ['save', true, 'ok'], 'cancellation' => ['cancel', false, 'result_capture_unavailable'],
            'cancellation rollback' => ['cancel', true, 'ok'], 'publication keeps historical version' => ['publish', false, 'ok']];
    }
}
