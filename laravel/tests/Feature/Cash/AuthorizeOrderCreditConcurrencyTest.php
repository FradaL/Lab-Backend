<?php

namespace Tests\Feature\Cash;

use App\Cash\AuthorizeOrderCredit;
use App\Cash\CashAuthority;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\ReportDeliveryPolicy;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class AuthorizeOrderCreditConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void { $this->artisan('migrate:fresh'); $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false); }

    public function test_simultaneous_authorizations_are_serialized_and_do_not_accumulate(): void
    {
        $lab=Laboratory::factory()->create(); ReportDeliveryPolicy::factory()->for($lab)->create(['mode'=>'payment_or_credit']);
        $order=LaboratoryOrder::factory()->for($lab)->create(['total'=>'80.00','subtotal'=>'80.00']); Subscription::factory()->for($lab)->create();
        $actor=$this->createLaboratoryMember($lab); $this->assignDirectLaboratoryPermission($actor,$lab,CashAuthority::CREDIT);
        $directory=sys_get_temp_dir().'/cash-credit-race-'.bin2hex(random_bytes(8)); mkdir($directory,0700); $children=[]; DB::disconnect();
        try {
            foreach ([0,1] as $index) { $pid=pcntl_fork(); $this->assertNotSame(-1,$pid); if ($pid===0) { DB::reconnect(); try { touch($directory.'/ready-'.$index); $deadline=microtime(true)+10; while(!file_exists($directory.'/go')&&microtime(true)<$deadline) usleep(1000); setPermissionsTeamId($lab->id); $result=app(AuthorizeOrderCredit::class)->authorize($lab,$actor,$order->id,['expected_revision'=>1,'amount'=>$index===0?'80.00':'90.00','reason'=>'Carrera ficticia'],'ficticio-credit-race-key-'.$index); $status=$result['replayed']?'replayed':'created'; } catch (ClinicalConflict $e) { $status=$e->errorCode; } catch (Throwable $e) { $status=$e::class.':'.$e->getMessage(); } file_put_contents($directory.'/result-'.$index,$status); DB::disconnect(); exit(0); } $children[]=$pid; }
            $deadline=microtime(true)+10; while((!file_exists($directory.'/ready-0')||!file_exists($directory.'/ready-1'))&&microtime(true)<$deadline) usleep(1000); touch($directory.'/go');
            foreach($children as $pid) { pcntl_waitpid($pid,$status); $this->assertSame(0,pcntl_wexitstatus($status)); }
            $results=[file_get_contents($directory.'/result-0'),file_get_contents($directory.'/result-1')]; sort($results); $this->assertSame(['cash_revision_conflict','created'],$results);
            DB::reconnect(); $this->assertDatabaseCount('laboratory_order_credit_events',1); $this->assertDatabaseHas('laboratory_order_financial_states',['laboratory_order_id'=>$order->id,'revision'=>2]); $this->assertContains(DB::table('laboratory_order_credit_events')->value('amount'),['80.00','90.00']);
        } finally { DB::reconnect(); foreach(glob($directory.'/*')?:[] as $path) unlink($path); rmdir($directory); }
    }
}
