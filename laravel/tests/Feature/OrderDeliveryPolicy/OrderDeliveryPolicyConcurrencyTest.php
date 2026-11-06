<?php

namespace Tests\Feature\OrderDeliveryPolicy;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\ReportDelivery\ReportDeliveryPolicyService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class OrderDeliveryPolicyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    #[DataProvider('scenarios')]
    public function test_creation_serializes_with_configuration_without_partial_order(bool $initial, bool $rollback, bool $creationFirst): void
    {
        $lab = Laboratory::factory()->create();
        $actor = $this->createLaboratoryMember($lab);
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assignAllOrderPermissions($actor, $lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, 'reports.delivery_policy.manage');
        if (! $initial) {
            app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'full_payment_required', 'reason' => 'FICTICIO — inicial']);
        }
        $payload = ['branch_id' => Branch::factory()->for($lab)->create()->id,
            'patient_id' => Patient::factory()->for($lab)->create()->id,
            'price_list_id' => PriceList::factory()->for($lab)->create()->id,
            'doctor_id' => null, 'commercial_client_id' => null, 'ordered_at' => '2026-11-06 12:00:00', 'notes' => 'FICTICIO — carrera BE-D02-03'];
        $directory = sys_get_temp_dir().'/d02-03-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                DB::reconnect();
                DB::statement("SET statement_timeout='15s'");
                file_put_contents($directory.'/pid', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $deadline = microtime(true) + 10;
                while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! file_exists($directory.'/go')) {
                    throw new \RuntimeException('No se habilitó el proceso independiente.');
                }
                setPermissionsTeamId($lab->id);
                if ($creationFirst) {
                    $result = app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 1, 'mode' => 'unrestricted', 'reason' => 'FICTICIO — cambio concurrente']);
                } else {
                    $order = app(CreateLaboratoryOrder::class)->execute($lab, $actor, $payload);
                    $result = $order->delivery_policy_snapshot;
                }
                file_put_contents($directory.'/result', json_encode(['ok' => true, 'value' => $result]));
            } catch (Throwable $exception) {
                file_put_contents($directory.'/result', json_encode(['ok' => false, 'code' => $exception->errorCode ?? null, 'message' => $exception->getMessage()]));
            }
            DB::disconnect();
            exit(0);
        }
        $waited = false;
        try {
            DB::reconnect();
            DB::statement("SET statement_timeout='15s'");
            DB::beginTransaction();
            if ($creationFirst) {
                app(CreateLaboratoryOrder::class)->execute($lab, $actor, $payload);
            } else {
                app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => $initial ? 0 : 1, 'mode' => 'unrestricted', 'reason' => 'FICTICIO — cambio concurrente']);
            }
            touch($directory.'/go');
            $deadline = microtime(true) + 10;
            $blocked = false;
            while (microtime(true) < $deadline) {
                if (file_exists($directory.'/pid')) {
                    $backend = (int) file_get_contents($directory.'/pid');
                    $blocked = (bool) DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock') AS blocked", [$backend])->blocked;
                    if ($blocked || file_exists($directory.'/result')) {
                        break;
                    }
                }
                usleep(1000);
            }
            $this->assertTrue($blocked, 'Debe observarse espera real de bloqueo entre conexiones PostgreSQL.');
            if ($rollback) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            pcntl_waitpid($pid, $status);
            $waited = true;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($directory.'/result'), true, flags: JSON_THROW_ON_ERROR);
            if ($initial && $rollback) {
                $this->assertFalse($result['ok']);
                $this->assertSame('delivery_policy_not_configured', $result['code']);
                $this->assertDatabaseCount('laboratory_orders', 0);
                $this->assertDatabaseCount('report_delivery_policies', 0);
            } else {
                $this->assertTrue($result['ok'], $result['message'] ?? '');
                $this->assertDatabaseCount('laboratory_orders', 1);
                $snapshot = LaboratoryOrder::firstOrFail()->delivery_policy_snapshot;
                $this->assertSame($creationFirst || $rollback ? 'full_payment_required' : 'unrestricted', $snapshot['mode']);
                $this->assertSame($creationFirst || $rollback || $initial ? 1 : 2, $snapshot['source_revision']);
                $this->assertSame($actor->id, $snapshot['configured_by']);
                $this->assertDatabaseHas('report_delivery_policy_changes', ['laboratory_id' => $lab->id, 'revision' => $snapshot['source_revision'], 'new_mode' => $snapshot['mode']]);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            touch($directory.'/go');
            if (! $waited) {
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function scenarios(): array
    {
        return ['primera selección confirmada' => [true, false, false],
            'primera selección revertida' => [true, true, false],
            'cambio confirmado' => [false, false, false],
            'cambio revertido' => [false, true, false],
            'creación antes de cambio' => [false, false, true]];
    }
}
