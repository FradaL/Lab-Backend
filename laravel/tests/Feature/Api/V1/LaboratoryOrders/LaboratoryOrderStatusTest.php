<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Http\Controllers\Api\V1\LaboratoryOrderController;
use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class LaboratoryOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('allowedTransitionProvider')]
    public function test_allowed_transitions_are_persisted(string $from, string $to): void
    {
        [$user, $laboratory, $order] = $this->orderContext(['status' => $from]);
        $before = $order->updated_at;
        Carbon::setTestNow($before->copy()->addMinute());

        try {
            $this->request($user, $laboratory, $order->id, ['status' => $to])
                ->assertOk()
                ->assertJsonPath('data.status', $to);
        } finally {
            Carbon::setTestNow();
        }

        $order->refresh();
        $this->assertSame($to, $order->status);
        $this->assertTrue($order->updated_at->greaterThan($before));
    }

    /** @return array<string, array{string, string}> */
    public static function allowedTransitionProvider(): array
    {
        return [
            'pending to in process' => [LaboratoryOrder::STATUS_PENDING, LaboratoryOrder::STATUS_IN_PROCESS],
            'pending to cancelled' => [LaboratoryOrder::STATUS_PENDING, LaboratoryOrder::STATUS_CANCELLED],
            'in process to completed' => [LaboratoryOrder::STATUS_IN_PROCESS, LaboratoryOrder::STATUS_COMPLETED],
            'in process to cancelled' => [LaboratoryOrder::STATUS_IN_PROCESS, LaboratoryOrder::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('forbiddenTransitionProvider')]
    public function test_forbidden_transitions_return_status_validation_error(string $from, string $to): void
    {
        [$user, $laboratory, $order] = $this->orderContext(['status' => $from]);
        $before = $order->getRawOriginal('updated_at');

        $this->request($user, $laboratory, $order->id, ['status' => $to])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $order->refresh();
        $this->assertSame($from, $order->status);
        $this->assertSame($before, $order->getRawOriginal('updated_at'));
    }

    /** @return array<string, array{string, string}> */
    public static function forbiddenTransitionProvider(): array
    {
        return [
            'pending cannot complete' => ['pending', 'completed'],
            'in process cannot return to pending' => ['in_process', 'pending'],
            'completed cannot return to pending' => ['completed', 'pending'],
            'completed cannot return to in process' => ['completed', 'in_process'],
            'completed cannot cancel' => ['completed', 'cancelled'],
            'cancelled cannot return to pending' => ['cancelled', 'pending'],
            'cancelled cannot return to in process' => ['cancelled', 'in_process'],
            'cancelled cannot complete' => ['cancelled', 'completed'],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_same_status_is_an_idempotent_no_op(string $status): void
    {
        [$user, $laboratory, $order] = $this->orderContext(['status' => $status]);
        $before = $order->getRawOriginal('updated_at');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->request($user, $laboratory, $order->id, ['status' => $status])
            ->assertOk()
            ->assertJsonPath('data.status', $status);

        $this->assertSame($before, $order->fresh()->getRawOriginal('updated_at'));
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_starts_with($sql, 'update "laboratory_orders"'),
        ));
        $this->assertSame($order->id, $response->json('data.id'));
    }

    /** @return array<string, array{string}> */
    public static function statusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'in process' => ['in_process'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_or_non_exact_status_values_are_rejected(mixed $status): void
    {
        [$user, $laboratory, $order] = $this->orderContext();

        $this->request($user, $laboratory, $order->id, ['status' => $status])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'uppercase' => ['IN_PROCESS'],
            'leading whitespace' => [' in_process'],
            'trailing whitespace' => ['in_process '],
            'hyphenated' => ['in-process'],
            'paid' => ['paid'],
            'empty' => [''],
            'null' => [null],
            'integer' => [1],
            'boolean' => [true],
            'array' => [['in_process']],
        ];
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_unknown_fields_are_rejected(string $field): void
    {
        [$user, $laboratory, $order] = $this->orderContext();

        $this->request($user, $laboratory, $order->id, [
            'status' => 'in_process',
            $field => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return [
            'laboratory id' => ['laboratory_id'],
            'updated at' => ['updated_at'],
            'notes' => ['notes'],
            'arbitrary' => ['foo'],
        ];
    }

    public function test_missing_status_is_rejected(): void
    {
        [$user, $laboratory, $order] = $this->orderContext();

        $this->request($user, $laboratory, $order->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_cross_tenant_and_missing_orders_return_same_404_before_payload_validation(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [$user, $laboratory] = $this->activeTenant($user);
        [, , $foreignOrder] = $this->orderContext(user: $user);

        $missing = $this->request($user, $laboratory, 999999, [])->assertNotFound();
        $foreign = $this->request($user, $laboratory, $foreignOrder->id, [])->assertNotFound();

        $this->assertSame($missing->getContent(), $foreign->getContent());
        $this->assertSame('pending', $foreignOrder->fresh()->status);
    }

    public function test_multi_lab_user_is_scoped_only_by_current_laboratory_header(): void
    {
        $user = User::factory()->create();
        [$user, $laboratoryA, $orderA] = $this->orderContext(user: $user);
        [$user, $laboratoryB, $orderB] = $this->orderContext(user: $user);

        $this->request($user, $laboratoryA, $orderB->id, ['status' => 'in_process'])->assertNotFound();
        $this->request($user, $laboratoryB, $orderB->id, ['status' => 'in_process'])->assertOk();

        $this->assertSame('pending', $orderA->fresh()->status);
        $this->assertSame('in_process', $orderB->fresh()->status);
    }

    public function test_non_numeric_route_identifier_is_not_matched(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson('/api/v1/laboratory-orders/not-an-id/status', ['status' => 'in_process'])
            ->assertNotFound();
    }

    public function test_inactive_historical_references_and_economic_values_do_not_block_transition(): void
    {
        [$user, $laboratory, $order] = $this->orderContext([
            'subtotal' => '150.00',
            'discount' => '10.00',
            'taxes' => '5.00',
            'total' => '145.00',
        ]);
        foreach ([
            'patients' => $order->patient_id,
            'doctors' => $order->doctor_id,
            'commercial_clients' => $order->commercial_client_id,
            'price_lists' => $order->price_list_id,
            'branches' => $order->branch_id,
        ] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['status' => 'inactive']);
        }

        $this->request($user, $laboratory, $order->id, ['status' => 'in_process'])
            ->assertOk()
            ->assertJsonPath('data.patient.id', $order->patient_id)
            ->assertJsonPath('data.doctor.id', $order->doctor_id)
            ->assertJsonPath('data.commercial_client.id', $order->commercial_client_id)
            ->assertJsonPath('data.price_list.id', $order->price_list_id)
            ->assertJsonPath('data.branch.id', $order->branch_id)
            ->assertJsonPath('data.subtotal', '150.00')
            ->assertJsonPath('data.total', '145.00');
    }

    public function test_particular_order_without_doctor_can_transition(): void
    {
        [$user, $laboratory, $order] = $this->orderContext([
            'doctor_id' => null,
            'commercial_client_id' => null,
        ]);

        $this->request($user, $laboratory, $order->id, ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.doctor', null)
            ->assertJsonPath('data.commercial_client', null);
    }

    public function test_transition_uses_only_order_and_resource_relation_queries(): void
    {
        [$user, $laboratory, $order] = $this->orderContext();
        $references = [$order->patient, $order->doctor, $order->commercialClient, $order->priceList, $order->branch, $order->createdBy];
        $before = collect($references)->mapWithKeys(fn ($model): array => [
            $model::class.'-'.$model->id => $model->getRawOriginal('updated_at'),
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->request($user, $laboratory, $order->id, ['status' => 'in_process'])
            ->assertOk();

        foreach (['commercial_client_price_lists', 'price_list_exams', 'laboratory_order_exams'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
        $this->assertCount(1, collect($queries)->filter(
            fn (string $sql): bool => str_starts_with($sql, 'update "laboratory_orders"'),
        ));
        foreach ($references as $reference) {
            $this->assertSame(
                $before[$reference::class.'-'.$reference->id],
                $reference->newQuery()->findOrFail($reference->id)->getRawOriginal('updated_at'),
            );
        }
        $this->assertSame([
            'id', 'code', 'ordered_at', 'status', 'notes', 'patient', 'doctor',
            'commercial_client', 'price_list', 'branch', 'subtotal', 'discount_type',
            'discount_value', 'discount', 'taxes', 'total', 'currency', 'created_by',
            'created_at', 'updated_at',
        ], array_keys($response->json('data')));
    }

    public function test_action_rechecks_locked_current_state_and_protects_terminal_result(): void
    {
        [$user, $laboratory, $order] = $this->orderContext(['status' => 'in_process']);
        $staleStatus = $order->status;

        $this->request($user, $laboratory, $order->id, ['status' => 'completed'])->assertOk();
        $this->assertSame('in_process', $staleStatus);
        $this->request($user, $laboratory, $order->id, ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_auth_context_access_and_subscription_pipeline_precede_workflow(): void
    {
        $url = '/api/v1/laboratory-orders/1/status';
        $this->patchJson($url, [])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson($url, [])->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')->patchJson($url, [])
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');

        $laboratory = Laboratory::factory()->create();
        $this->request($user, $laboratory, 1, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->request($user, $laboratory, 1, [])->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_route_controller_and_openapi_expose_exact_status_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-orders'))
            ->values();
        $statusRoute = $routes->first(fn ($route): bool => in_array('PATCH', $route->methods(), true));

        $this->assertCount(8, $routes);
        $this->assertNotNull($statusRoute);
        $this->assertSame('api/v1/laboratory-orders/{laboratoryOrder}/status', $statusRoute->uri());
        $this->assertSame(['PATCH'], $statusRoute->methods());
        $this->assertContains('saas', $statusRoute->middleware());
        $this->assertSame('[0-9]+', $statusRoute->wheres['laboratoryOrder']);
        $this->assertSame([
            'addExam', 'listExams', 'removeDiscount', 'removeExam', 'show', 'store', 'updateDiscount', 'updateStatus',
        ], collect((new ReflectionClass(LaboratoryOrderController::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LaboratoryOrderController::class)
            ->pluck('name')->sort()->values()->all());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/laboratory-orders/{laboratoryOrder}/status']['patch'];
        $schema = $document['components']['schemas']['UpdateLaboratoryOrderStatusInput'];
        $operationCount = collect($document['paths'])->sum(fn (array $path): int => count(array_intersect_key(
            $path,
            array_flip(['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']),
        )));

        $this->assertSame(65, $operationCount);
        $this->assertCount(73, Route::getRoutes()->getRoutes());
        $this->assertSame(['status'], $schema['required']);
        $this->assertSame(['status'], array_keys($schema['properties']));
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(LaboratoryOrder::STATUSES, $schema['properties']['status']['enum']);
        $this->assertSame('#/components/schemas/UpdateLaboratoryOrderStatusInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/LaboratoryOrderResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertStringContainsString('pending', strtolower($operation['description']));
        $this->assertStringContainsString('terminales', strtolower($operation['description']));
        $this->assertStringContainsString('no-op', strtolower($operation['description']));
    }

    /** @param array<string, mixed> $attributes */
    private function orderContext(array $attributes = [], ?User $user = null): array
    {
        [$user, $laboratory] = $this->activeTenant($user);
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $doctor = Doctor::factory()->for($laboratory)->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create(['currency' => 'GTQ']);
        $order = LaboratoryOrder::factory()->for($laboratory)->create(array_replace([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'created_by' => $user->id,
        ], $attributes));

        return [$user, $laboratory, $order];
    }

    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [$user, $laboratory];
    }

    /** @param array<string, mixed> $payload */
    private function request(User $user, Laboratory $laboratory, int $orderId, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-orders/{$orderId}/status", $payload);
    }
}
