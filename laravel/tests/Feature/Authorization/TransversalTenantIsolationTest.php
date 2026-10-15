<?php

namespace Tests\Feature\Authorization;

use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TransversalTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('resources')]
    public function test_valid_foreign_ids_cannot_be_read_or_modified_after_switching_context(string $model, string $route, string $permission, string $status): void
    {
        $a = Laboratory::factory()->create();
        $b = Laboratory::factory()->create();
        foreach ([$a, $b] as $laboratory) {
            Subscription::factory()->for($laboratory)->create();
        }
        $user = $this->createLaboratoryMember($a);
        $user->laboratories()->attach($b, ['is_active' => true]);
        foreach ([$a, $b] as $laboratory) {
            $this->assignDirectLaboratoryPermissions($user, $laboratory, [$permission.'.view', $permission.'.change_status']);
        }
        $record = $model::factory()->for($b)->create();
        $before = $record->fresh()->getRawOriginal();
        $audits = DB::table('audit_logs')->count();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $b->id)
            ->getJson("/api/v1/{$route}/{$record->id}")->assertOk();
        $this->withHeader('X-Laboratory-ID', (string) $a->id);
        $foreign = $this->getJson("/api/v1/{$route}/{$record->id}")->assertNotFound();
        $missing = $this->getJson("/api/v1/{$route}/".($model::query()->max('id') + 1))->assertNotFound();
        // Patient's existing 404 echoes the requested ID; normalize that caller-supplied value.
        $this->assertSame(
            preg_replace('/ \d+$/', ' <id>', $missing->json('message')),
            preg_replace('/ \d+$/', ' <id>', $foreign->json('message')),
        );
        $foreign->assertJsonMissingPath('data');
        $this->patchJson("/api/v1/{$route}/{$record->id}/status", ['status' => $status])->assertNotFound();
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->withHeader('X-Laboratory-ID', (string) $b->id)
            ->getJson("/api/v1/{$route}/{$record->id}")->assertOk();
    }

    public static function resources(): iterable
    {
        yield 'patients' => [Patient::class, 'patients', 'patients', 'inactive'];
        yield 'doctors' => [Doctor::class, 'doctors', 'doctors', 'inactive'];
        yield 'exams' => [LaboratoryExam::class, 'laboratory-exams', 'laboratory_exams', 'inactive'];
        yield 'orders' => [LaboratoryOrder::class, 'laboratory-orders', 'orders', 'cancelled'];
        yield 'price lists' => [PriceList::class, 'price-lists', 'price_lists', 'inactive'];
    }

    public function test_foreign_order_line_cannot_be_deleted_or_exam_associated_in_current_laboratory(): void
    {
        $a = Laboratory::factory()->create();
        $b = Laboratory::factory()->create();
        Subscription::factory()->for($a)->create();
        $user = $this->createLaboratoryMember($a);
        $this->assignAllOrderPermissions($user, $a);
        $orderA = LaboratoryOrder::factory()->for($a)->create();
        $lineB = LaboratoryOrderExam::factory()->for($b)->create();
        $before = $lineB->fresh()->getRawOriginal();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $a->id);
        $this->deleteJson("/api/v1/laboratory-orders/{$orderA->id}/exams/{$lineB->id}")->assertNotFound();
        $this->deleteJson("/api/v1/laboratory-orders/{$lineB->laboratory_order_id}/exams/{$lineB->id}")->assertNotFound();
        $this->postJson("/api/v1/laboratory-orders/{$orderA->id}/exams", ['laboratory_exam_id' => $lineB->laboratory_exam_id])
            ->assertUnprocessable()->assertJsonValidationErrors('laboratory_exam_id');
        $this->assertSame($before, $lineB->fresh()->getRawOriginal());
        $this->assertDatabaseCount('laboratory_order_exams', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
