<?php

namespace Tests\Feature\PhysicalSamples;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySample;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\SampleType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class PhysicalSampleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('races')]
    public function test_independent_connections_serialize_sample_operations(string $writer, string $reader, string $expected): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $lab = Laboratory::factory()->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($actor, $lab, RbacCatalog::PERMISSIONS);
        setPermissionsTeamId($lab->id);
        $branch = Branch::factory()->for($lab)->create(['status' => 'active']);
        $type = SampleType::factory()->for($lab)->create(['status' => 'active']);
        $area = LaboratoryArea::factory()->for($lab)->create(['status' => 'active']);
        $clinical = app(ClinicalExamService::class);
        $draft = $clinical->create($lab, $actor, ['code' => 'RACE', 'name' => 'FICTIONAL', 'laboratory_area_id' => $area->id,
            'configuration' => ['schema_version' => 2, 'sample_mode' => 'physical', 'sample_requirements' => [['key' => 'main', 'sample_type_id' => $type->id]], 'parameters' => [['code' => 'OBS', 'name' => 'FICTIONAL', 'type' => 'text']]]]);
        $clinical->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1);
        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
        $exam->update(['status' => 'active']);
        $prices = PriceList::factory()->for($lab)->create(['status' => 'active', 'currency' => 'GTQ']);
        PriceListExam::factory()->for($lab)->create(['price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id, 'price' => '25.00', 'status' => 'active']);
        $order = LaboratoryOrder::factory()->for($lab)->create(['price_list_id' => $prices->id, 'currency' => 'GTQ', 'status' => 'pending']);
        $line = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $exam->id, $actor);
        $requirement = $line->sampleRequirements->first()->id;
        $input = ['idempotency_key' => 'race-registration', 'sample_type_id' => $type->id, 'provenance' => 'patient_provided', 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id];
        $service = app(LaboratorySampleService::class);
        $sampleId = null;
        $associationId = null;
        if ($writer !== 'register') {
            $sampleId = $service->register($lab, $actor, $order->id, 'receiveNew', $input)['data']['id'];
            if ($writer !== 'associate') {
                $service->associate($lab, $actor, $order->id, $sampleId, ['sample_requirement_ids' => [$requirement]]);
                $associationId = DB::table('laboratory_sample_associations')->value('id');
            }
            if (in_array($writer, ['start', 'release', 'reject_accepted'], true)) {
                $service->evaluate($lab, $actor, $order->id, $sampleId, 'accepted');
            }
        }
        $operation = function (string $name) use ($lab, $actor, $order, $line, $sampleId, $requirement, $associationId, $input): void {
            $service = app(LaboratorySampleService::class);
            match ($name) {
                'register_new' => $service->register($lab, $actor, $order->id, 'receiveNew', [...$input, 'idempotency_key' => 'race-registration-new']),
                'register' => $service->register($lab, $actor, $order->id, 'receiveNew', $input),
                'associate' => $service->associate($lab, $actor, $order->id, $sampleId, ['sample_requirement_ids' => [$requirement]]),
                'start' => $service->start($lab, $actor, $order->id, $line->id),
                'release' => $service->release($lab, $actor, $order->id, $sampleId, $associationId, ['reason' => 'FICTIONAL correction']),
                'reject', 'reject_accepted' => $service->evaluate($lab, $actor, $order->id, $sampleId, 'rejected', ['reason' => 'FICTIONAL rejection']),
                default => $service->evaluate($lab, $actor, $order->id, $sampleId, 'accepted'),
            };
        };
        $directory = sys_get_temp_dir().'/be05-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents($directory.'/pid', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $deadline = microtime(true) + 15;
                while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($directory.'/go')) {
                    throw new \RuntimeException('Writer never released the worker.');
                }
                $operation($reader);
                $result = ['status' => 'ok'];
            } catch (ClinicalConflict $exception) {
                $result = ['status' => $exception->errorCode];
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
            $operation($writer);
            touch($directory.'/go');
            $deadline = microtime(true) + 15;
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
            $this->assertTrue($blocked, 'An independent PostgreSQL connection must actually wait on the writer.');
            DB::commit();
            pcntl_waitpid($pid, $exit);
            $this->assertSame(0, pcntl_wexitstatus($exit));
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $result['status'], $result['message'] ?? '');
            $count = $reader === 'register_new' ? 2 : 1;
            $this->assertDatabaseCount('laboratory_samples', $count);
            $this->assertSame($count, LaboratorySample::distinct()->count('identifier'));
            if (in_array($writer, ['register', 'associate'], true)) {
                $this->assertDatabaseCount('laboratory_sample_events', $writer === 'register' ? $count : 2);
                $this->assertDatabaseCount('laboratory_sample_associations', $writer === 'register' ? 0 : 1);
            } elseif ($writer === 'start') {
                $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 1);
                $this->assertSame('accepted', LaboratorySample::findOrFail($sampleId)->status);
                $this->assertDatabaseHas('laboratory_sample_associations', ['id' => $associationId, 'released_at' => null]);
            } else {
                $this->assertDatabaseCount('laboratory_sample_processing_dependencies', 0);
                $this->assertSame('pending', DB::table('laboratory_sample_requirement_satisfactions')->value('status'));
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
        return [
            'accept then reject' => ['accept', 'reject', 'ok'],
            'reject then accept' => ['reject', 'accept', 'sample_state_conflict'],
            'start then reject' => ['start', 'reject', 'sample_has_clinical_activity'],
            'reject accepted then start' => ['reject_accepted', 'start', 'order_exam_not_ready'],
            'start then release' => ['start', 'release', 'association_has_clinical_activity'],
            'release then start' => ['release', 'start', 'order_exam_not_ready'],
            'duplicate associations' => ['associate', 'associate', 'ok'],
            'duplicate creation' => ['register', 'register', 'ok'],
            'distinct concurrent identifiers' => ['register', 'register_new', 'ok'],
        ];
    }
}
