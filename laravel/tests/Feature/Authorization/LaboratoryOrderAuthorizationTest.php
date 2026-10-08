<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LaboratoryOrderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    #[DataProvider('roleMatrixProvider')]
    public function test_seeded_roles_enforce_every_order_route(
        string $role,
        array $allowedCapabilities,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $context = $this->orderContext($user, $laboratory);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, $role);

        foreach ($this->routeCases($context) as $capability => [$method, $uri, $payload]) {
            $response = $this->request($user, $laboratory, $method, $uri, $payload);

            if (in_array($capability, $allowedCapabilities, true)) {
                $this->assertNotSame(403, $response->status(), "{$role} was denied {$capability}");
            } else {
                $response->assertForbidden();
            }
        }
    }

    /** @return array<string, array{string, array<int, string>}> */
    public static function roleMatrixProvider(): array
    {
        $all = ['view_index', 'create', 'view_exams', 'add_exam', 'remove_exam', 'set_discount', 'remove_discount', 'change_status', 'view_detail'];

        return [
            'owner' => ['owner', $all],
            'administrator' => ['administrator', $all],
            'receptionist' => ['receptionist', ['view_index', 'create', 'view_exams', 'add_exam', 'remove_exam', 'view_detail']],
            'cashier' => ['cashier', ['view_index', 'view_exams', 'set_discount', 'remove_discount', 'view_detail']],
            'laboratory technician' => ['laboratory_technician', ['view_index', 'view_exams', 'change_status', 'view_detail']],
            'viewer' => ['viewer', ['view_index', 'view_exams', 'view_detail']],
        ];
    }

    public function test_receptionist_and_cashier_roles_combine_for_the_complete_reception_flow(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $context = $this->orderContext($user, $laboratory, false);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, 'receptionist');
        $this->assignLaboratoryRole($user, $laboratory, 'cashier');

        $authorization = $this->request(
            $user,
            $laboratory,
            'GET',
            '/api/v1/auth/authorization',
        )->assertOk();

        $authorization->assertJsonPath('data.roles', ['cashier', 'receptionist']);
        foreach ([
            'orders.view',
            'orders.create',
            'orders.add_exam',
            'orders.remove_exam',
            'orders.manage_discount',
        ] as $permission) {
            $this->assertContains($permission, $authorization->json('data.permissions'));
        }
        $this->assertNotContains('orders.change_status', $authorization->json('data.permissions'));

        $orderId = $this->request(
            $user,
            $laboratory,
            'POST',
            '/api/v1/laboratory-orders',
            $context['create_payload'],
        )->assertCreated()
            ->assertJsonPath('data.status', LaboratoryOrder::STATUS_PENDING)
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonPath('data.total', '0.00')
            ->json('data.id');

        $firstLineId = $this->request(
            $user,
            $laboratory,
            'POST',
            "/api/v1/laboratory-orders/{$orderId}/exams",
            ['laboratory_exam_id' => $context['exam']->id],
        )->assertCreated()
            ->assertJsonPath('data.unit_price', '35.00')
            ->json('data.id');

        $secondLineId = $this->request(
            $user,
            $laboratory,
            'POST',
            "/api/v1/laboratory-orders/{$orderId}/exams",
            ['laboratory_exam_id' => $context['exam']->id],
        )->assertCreated()
            ->assertJsonPath('data.unit_price', '35.00')
            ->json('data.id');

        $this->assertNotSame($firstLineId, $secondLineId);

        $this->request(
            $user,
            $laboratory,
            'PUT',
            "/api/v1/laboratory-orders/{$orderId}/discount",
            ['type' => 'percentage', 'value' => '10.00'],
        )->assertOk()
            ->assertJsonPath('data.subtotal', '70.00')
            ->assertJsonPath('data.discount', '7.00')
            ->assertJsonPath('data.total', '63.00');

        $this->request($user, $laboratory, 'GET', "/api/v1/laboratory-orders/{$orderId}/exams")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->request($user, $laboratory, 'GET', "/api/v1/laboratory-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.subtotal', '70.00')
            ->assertJsonPath('data.total', '63.00');

        $this->request(
            $user,
            $laboratory,
            'DELETE',
            "/api/v1/laboratory-orders/{$orderId}/exams/{$firstLineId}",
        )->assertNoContent();

        $this->request($user, $laboratory, 'GET', "/api/v1/laboratory-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.subtotal', '35.00')
            ->assertJsonPath('data.discount', '3.50')
            ->assertJsonPath('data.total', '31.50');
        $this->request($user, $laboratory, 'GET', "/api/v1/laboratory-orders/{$orderId}/exams")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondLineId);

        $this->assertSame([
            'order.created',
            'order_exam.added',
            'order_exam.added',
            'order.discount_set',
            'order_exam.removed',
        ], AuditLog::query()->orderBy('id')->pluck('event')->all());
        $this->assertSame([$laboratory->id], AuditLog::query()->pluck('laboratory_id')->unique()->all());
        $this->assertSame([$user->id], AuditLog::query()->pluck('user_id')->unique()->all());

        $auditCount = AuditLog::query()->count();
        $this->request(
            $user,
            $laboratory,
            'PATCH',
            "/api/v1/laboratory-orders/{$orderId}/status",
            ['status' => LaboratoryOrder::STATUS_IN_PROCESS],
        )->assertForbidden();
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, LaboratoryOrder::query()->findOrFail($orderId)->status);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_direct_view_permission_is_tenant_scoped_without_stale_state(): void
    {
        [$user, $laboratoryA] = $this->activeTenant();
        [, $laboratoryB] = $this->activeTenant($user);
        $this->assignDirectLaboratoryPermission($user, $laboratoryA, 'orders.view');

        $this->request($user, $laboratoryA, 'GET', '/api/v1/laboratory-orders')->assertOk();
        $this->request($user, $laboratoryB, 'GET', '/api/v1/laboratory-orders')->assertForbidden();
        $this->request($user, $laboratoryA, 'GET', '/api/v1/laboratory-orders')->assertOk();
    }

    public function test_direct_create_permission_is_tenant_scoped_and_writes_only_in_authorized_tenant(): void
    {
        [$user, $laboratoryA] = $this->activeTenant();
        [, $laboratoryB] = $this->activeTenant($user);
        $contextA = $this->orderContext($user, $laboratoryA, false);
        $contextB = $this->orderContext($user, $laboratoryB, false);
        $this->assignDirectLaboratoryPermission($user, $laboratoryA, 'orders.create');

        $this->request($user, $laboratoryA, 'POST', '/api/v1/laboratory-orders', $contextA['create_payload'])->assertCreated();
        $this->request($user, $laboratoryB, 'POST', '/api/v1/laboratory-orders', $contextB['create_payload'])->assertForbidden();
        $this->request($user, $laboratoryA, 'POST', '/api/v1/laboratory-orders', $contextA['create_payload'])->assertCreated();

        $this->assertSame(2, LaboratoryOrder::query()->where('laboratory_id', $laboratoryA->id)->count());
        $this->assertSame(0, LaboratoryOrder::query()->where('laboratory_id', $laboratoryB->id)->count());
        $this->assertSame([$laboratoryA->id], AuditLog::query()->pluck('laboratory_id')->unique()->all());
    }

    public function test_role_assignments_are_tenant_scoped_without_stale_permission_leakage(): void
    {
        [$user, $laboratoryA] = $this->activeTenant();
        [, $laboratoryB] = $this->activeTenant($user);
        $contextA = $this->orderContext($user, $laboratoryA, false);
        $contextB = $this->orderContext($user, $laboratoryB, false);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratoryA, 'receptionist');
        $this->assignLaboratoryRole($user, $laboratoryB, 'viewer');

        $this->request($user, $laboratoryA, 'POST', '/api/v1/laboratory-orders', $contextA['create_payload'])->assertCreated();
        $this->request($user, $laboratoryB, 'POST', '/api/v1/laboratory-orders', $contextB['create_payload'])->assertForbidden();
        $this->request($user, $laboratoryB, 'GET', '/api/v1/laboratory-orders')->assertOk();
        $this->request($user, $laboratoryA, 'POST', '/api/v1/laboratory-orders', $contextA['create_payload'])->assertCreated();
    }

    public function test_cross_tenant_order_and_line_are_neutral_404_for_reads_and_mutations(): void
    {
        [$owner, $laboratoryA] = $this->activeTenant();
        [, $laboratoryB] = $this->activeTenant($owner);
        $context = $this->orderContext($owner, $laboratoryA);
        $this->assignAllOrderPermissions($owner, $laboratoryB);
        $order = $context['order'];
        $line = $context['line'];
        $beforeOrder = $order->only(['status', 'subtotal', 'discount_type', 'discount_value', 'discount', 'total']);
        $beforeAudits = AuditLog::query()->count();

        $requests = [
            ['GET', "/api/v1/laboratory-orders/{$order->id}", []],
            ['GET', "/api/v1/laboratory-orders/{$order->id}/exams", []],
            ['POST', "/api/v1/laboratory-orders/{$order->id}/exams", ['laboratory_exam_id' => $context['exam']->id]],
            ['DELETE', "/api/v1/laboratory-orders/{$order->id}/exams/{$line->id}", []],
            ['PUT', "/api/v1/laboratory-orders/{$order->id}/discount", ['type' => 'amount', 'value' => '1.00']],
            ['DELETE', "/api/v1/laboratory-orders/{$order->id}/discount", []],
            ['PATCH', "/api/v1/laboratory-orders/{$order->id}/status", ['status' => LaboratoryOrder::STATUS_IN_PROCESS]],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $this->request($owner, $laboratoryB, $method, $uri, $payload)->assertNotFound();
        }

        $this->assertSame($beforeOrder, $order->fresh()->only(array_keys($beforeOrder)));
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line->id]);
        $this->assertSame($beforeAudits, AuditLog::query()->count());
    }

    public function test_saas_failures_precede_order_permission_checks(): void
    {
        $laboratory = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratory, false);
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'orders.view');
        $this->createSubscription($laboratory);

        $this->request($user, $laboratory, 'GET', '/api/v1/laboratory-orders')
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $user->laboratories()->updateExistingPivot($laboratory, ['is_active' => true]);
        $laboratory->subscriptions()->delete();

        $this->request($user, $laboratory, 'GET', '/api/v1/laboratory-orders')
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_permission_denial_precedes_validation_on_order_mutations(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $context = $this->orderContext($user, $laboratory);

        foreach ([
            ['POST', '/api/v1/laboratory-orders'],
            ['POST', "/api/v1/laboratory-orders/{$context['order']->id}/exams"],
            ['PUT', "/api/v1/laboratory-orders/{$context['order']->id}/discount"],
            ['PATCH', "/api/v1/laboratory-orders/{$context['order']->id}/status"],
        ] as [$method, $uri]) {
            $this->request($user, $laboratory, $method, $uri)->assertForbidden();
        }
    }

    public function test_denied_order_mutations_change_no_business_or_audit_state(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $context = $this->orderContext($user, $laboratory);
        $order = $context['order'];
        $line = $context['line'];
        $tables = ['laboratory_orders', 'laboratory_order_exams', 'audit_logs'];
        $counts = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $beforeOrder = $order->only(['status', 'subtotal', 'discount_type', 'discount_value', 'discount', 'total']);

        foreach ([
            ['POST', '/api/v1/laboratory-orders', $context['create_payload']],
            ['POST', "/api/v1/laboratory-orders/{$order->id}/exams", ['laboratory_exam_id' => $context['exam']->id]],
            ['DELETE', "/api/v1/laboratory-orders/{$order->id}/exams/{$line->id}", []],
            ['PUT', "/api/v1/laboratory-orders/{$order->id}/discount", ['type' => 'amount', 'value' => '1.00']],
            ['DELETE', "/api/v1/laboratory-orders/{$order->id}/discount", []],
            ['PATCH', "/api/v1/laboratory-orders/{$order->id}/status", ['status' => LaboratoryOrder::STATUS_IN_PROCESS]],
        ] as [$method, $uri, $payload]) {
            $this->request($user, $laboratory, $method, $uri, $payload)->assertForbidden();
        }

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} changed after authorization denial");
        }
        $this->assertSame($beforeOrder, $order->fresh()->only(array_keys($beforeOrder)));
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line->id]);
    }

    public function test_demo_admin_and_receptionist_receive_expected_order_access(): void
    {
        $laboratory = Laboratory::factory()->create(['nit' => '000000000001']);
        $admin = User::factory()->create(['email' => 'admin@donqerlab.test']);
        $receptionist = User::factory()->create(['email' => 'reception@donqerlab.test']);
        foreach ([$admin, $receptionist] as $user) {
            $user->laboratories()->attach($laboratory, ['is_active' => true]);
        }
        $this->createSubscription($laboratory);
        $context = $this->orderContext($admin, $laboratory);
        $this->seed(RolePermissionSeeder::class);

        $this->request($admin, $laboratory, 'PUT', "/api/v1/laboratory-orders/{$context['order']->id}/discount", [])
            ->assertUnprocessable();
        $this->request($receptionist, $laboratory, 'POST', '/api/v1/laboratory-orders', [])
            ->assertUnprocessable();
        $this->request($receptionist, $laboratory, 'PUT', "/api/v1/laboratory-orders/{$context['order']->id}/discount", [])
            ->assertForbidden();
        $this->request($receptionist, $laboratory, 'PATCH', "/api/v1/laboratory-orders/{$context['order']->id}/status", [])
            ->assertForbidden();
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $laboratory = Laboratory::factory()->create();
        $user ??= $this->createLaboratoryMember($laboratory);
        if (! $user->laboratories()->whereKey($laboratory->id)->exists()) {
            $user->laboratories()->attach($laboratory, ['is_active' => true]);
        }
        $this->createSubscription($laboratory);

        return [$user, $laboratory];
    }

    private function createSubscription(Laboratory $laboratory): void
    {
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function orderContext(User $user, Laboratory $laboratory, bool $withOrder = true): array
    {
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create(['currency' => 'GTQ']);
        $exam = LaboratoryExam::factory()->for($laboratory)->create(['status' => LaboratoryExam::STATUS_ACTIVE]);
        PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '35.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);
        $createPayload = [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => null,
            'commercial_client_id' => null,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-03 14:30:00',
            'notes' => null,
        ];
        if (! $withOrder) {
            return compact('branch', 'patient', 'priceList', 'exam') + ['create_payload' => $createPayload];
        }
        $order = LaboratoryOrder::factory()->particular()->withoutDoctor()->for($laboratory)->create([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'price_list_id' => $priceList->id,
            'created_by' => $user->id,
            'status' => LaboratoryOrder::STATUS_PENDING,
            'subtotal' => '35.00',
            'total' => '35.00',
            'currency' => 'GTQ',
        ]);
        $line = LaboratoryOrderExam::factory()->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'unit_price' => '35.00',
        ]);

        return compact('branch', 'patient', 'priceList', 'exam', 'order', 'line') + ['create_payload' => $createPayload];
    }

    /** @param array<string, mixed> $context
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    private function routeCases(array $context): array
    {
        $order = $context['order'];
        $line = $context['line'];

        return [
            'view_index' => ['GET', '/api/v1/laboratory-orders', []],
            'create' => ['POST', '/api/v1/laboratory-orders', []],
            'view_exams' => ['GET', "/api/v1/laboratory-orders/{$order->id}/exams", []],
            'add_exam' => ['POST', "/api/v1/laboratory-orders/{$order->id}/exams", []],
            'remove_exam' => ['DELETE', "/api/v1/laboratory-orders/{$order->id}/exams/{$line->id}", []],
            'set_discount' => ['PUT', "/api/v1/laboratory-orders/{$order->id}/discount", []],
            'remove_discount' => ['DELETE', "/api/v1/laboratory-orders/{$order->id}/discount", []],
            'change_status' => ['PATCH', "/api/v1/laboratory-orders/{$order->id}/status", []],
            'view_detail' => ['GET', "/api/v1/laboratory-orders/{$order->id}", []],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function request(
        User $user,
        Laboratory $laboratory,
        string $method,
        string $uri,
        array $payload = [],
    ): TestResponse {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->json($method, $uri, $payload);
    }
}
