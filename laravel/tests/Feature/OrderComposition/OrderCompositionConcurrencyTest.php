<?php

namespace Tests\Feature\OrderComposition;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalExamService;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

final class OrderCompositionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('writers')]
    public function test_add_waits_for_catalog_writer_and_uses_only_committed_state(string $writer): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'), 'PostgreSQL concurrency requires pcntl.');
        $lab = Laboratory::factory()->create();
        $actor = User::factory()->create();
        $prices = PriceList::factory()->for($lab)->create(['status' => 'active', 'currency' => 'GTQ']);
        $order = LaboratoryOrder::factory()->for($lab)->create(['price_list_id' => $prices->id, 'currency' => 'GTQ', 'status' => 'pending']);
        $exam = LaboratoryExam::factory()->for($lab)->orderable()->create(['status' => 'active']);
        $price = PriceListExam::factory()->for($lab)->create(['price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id, 'price' => '25.00', 'status' => 'active']);
        $service = app(ClinicalExamService::class);
        $originalVersion = $service->current($lab, $exam->id);
        $draft = $service->fork($lab, $actor, $exam->id, $originalVersion->id);
        $directory = sys_get_temp_dir().'/be04-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                $backend = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                file_put_contents($directory.'/pid', (string) $backend);
                $deadline = microtime(true) + 10;
                while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($directory.'/go')) {
                    throw new \RuntimeException('Parent did not release worker.');
                }
                $line = app(AddExamToLaboratoryOrder::class)->execute(Laboratory::findOrFail($lab->id), $order->id, $exam->id);
                file_put_contents($directory.'/result', json_encode(['status' => 'ok', 'price' => $line->unit_price, 'version' => $line->clinical_version_id], JSON_THROW_ON_ERROR));
            } catch (ValidationException $exception) {
                file_put_contents($directory.'/result', json_encode(['status' => 'commercial_unavailable'], JSON_THROW_ON_ERROR));
            } catch (ClinicalConflict $exception) {
                file_put_contents($directory.'/result', json_encode(['status' => $exception->errorCode], JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                file_put_contents($directory.'/result', json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            }
            DB::disconnect();
            exit(0);
        }
        try {
            DB::reconnect();
            DB::beginTransaction();
            match ($writer) {
                'publication', 'publication_rollback' => $service->publish($lab, $actor, $exam->id, $draft->id, 1),
                'exam_deactivation' => LaboratoryExam::whereKey($exam->id)->update(['status' => 'inactive']),
                'price_deactivation' => PriceListExam::whereKey($price->id)->update(['status' => 'inactive']),
                'list_deactivation' => PriceList::whereKey($prices->id)->update(['status' => 'inactive']),
                default => PriceListExam::whereKey($price->id)->update(['price' => '40.00']),
            };
            touch($directory.'/go');
            $deadline = microtime(true) + 10;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($directory.'/pid')) {
                    $backend = (int) file_get_contents($directory.'/pid');
                    $blocked = (bool) DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock') AS blocked", [$backend])->blocked;
                    if ($blocked || file_exists($directory.'/result')) {
                        break;
                    }
                }
                usleep(1000);
            }
            $this->assertTrue($blocked, 'The independent add connection must actually wait on the writer lock.');
            if (str_ends_with($writer, '_rollback')) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            if ($writer === 'exam_deactivation') {
                $this->assertSame('clinical_exam_unavailable', $result['status']);
                $this->assertDatabaseCount('laboratory_order_exams', 0);
            } elseif (in_array($writer, ['price_deactivation', 'list_deactivation'], true)) {
                $this->assertSame('commercial_unavailable', $result['status']);
                $this->assertDatabaseCount('laboratory_order_exams', 0);
            } else {
                $this->assertSame('ok', $result['status'], $result['message'] ?? '');
                $this->assertSame($writer === 'price' ? '40.00' : '25.00', $result['price']);
                $this->assertSame($writer === 'publication' ? $draft->id : $originalVersion->id, $result['version']);
                $this->assertSame($result['price'], $order->fresh()->total);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            touch($directory.'/go');
            pcntl_waitpid($pid, $status);
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function writers(): array
    {
        return ['published version' => ['publication'], 'price' => ['price'], 'exam inactive' => ['exam_deactivation'], 'price inactive' => ['price_deactivation'], 'list inactive' => ['list_deactivation'], 'price rollback' => ['price_rollback'], 'publication rollback' => ['publication_rollback']];
    }
}
