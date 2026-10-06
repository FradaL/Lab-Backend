<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Http\Controllers\Api\V1\LaboratoryOrderController;
use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class LaboratoryOrderStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_order_is_created_with_exact_server_owned_values_and_resource(): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->activeContext();

        $response = $this->request($user, $laboratory, $this->payload($branch, $patient, $priceList, $doctor, $client))
            ->assertCreated();

        $order = LaboratoryOrder::query()->sole();
        $this->assertSame($laboratory->id, $order->laboratory_id);
        $this->assertSame($branch->id, $order->branch_id);
        $this->assertSame($patient->id, $order->patient_id);
        $this->assertSame($doctor->id, $order->doctor_id);
        $this->assertSame($client->id, $order->commercial_client_id);
        $this->assertSame($client->name, $order->commercial_client_name);
        $this->assertSame($client->type, $order->commercial_client_type);
        $this->assertSame($priceList->id, $order->price_list_id);
        $this->assertSame($priceList->name, $order->price_list_name);
        $this->assertSame($user->id, $order->created_by);
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->status);
        $this->assertSame('2026-10-03 14:30:00', $order->ordered_at->format('Y-m-d H:i:s'));
        $this->assertSame('Observaciones opcionales', $order->notes);
        $this->assertSame('0.00', $order->subtotal);
        $this->assertNull($order->discount_type);
        $this->assertNull($order->discount_value);
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame('0.00', $order->total);
        $this->assertSame($priceList->currency, $order->currency);
        $this->assertMatchesRegularExpression('/\AORD-[0-9A-HJKMNP-TV-Z]{26}\z/', $order->code);
        $this->assertLessThanOrEqual(45, strlen($order->code));

        $this->assertSame([
            'id', 'code', 'ordered_at', 'status', 'notes', 'patient', 'doctor',
            'commercial_client', 'price_list', 'branch', 'subtotal', 'discount_type',
            'discount_value', 'discount', 'taxes', 'total', 'currency', 'created_by',
            'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertSame(['id', 'first_names', 'last_names'], array_keys($response->json('data.patient')));
        $this->assertSame(['id', 'first_names', 'last_names'], array_keys($response->json('data.doctor')));
        $this->assertSame(['id', 'name', 'type'], array_keys($response->json('data.commercial_client')));
        $this->assertSame(['id', 'name', 'currency'], array_keys($response->json('data.price_list')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.branch')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.created_by')));
        $response
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.doctor.id', $doctor->id)
            ->assertJsonPath('data.commercial_client.id', $client->id)
            ->assertJsonPath('data.price_list.id', $priceList->id)
            ->assertJsonPath('data.price_list.currency', $priceList->currency)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.created_by.id', $user->id)
            ->assertJsonPath('data.ordered_at', '2026-10-03 14:30:00')
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonPath('data.discount_type', null)
            ->assertJsonPath('data.discount_value', null)
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.taxes', '0.00')
            ->assertJsonPath('data.total', '0.00')
            ->assertJsonPath('data.currency', $priceList->currency)
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.branch_id')
            ->assertJsonMissingPath('data.patient_id')
            ->assertJsonMissingPath('data.doctor_id')
            ->assertJsonMissingPath('data.commercial_client_id')
            ->assertJsonMissingPath('data.price_list_id')
            ->assertJsonMissingPath('data.exams');
    }

    public function test_particular_order_without_doctor_and_notes_uses_explicit_nondefault_list(): void
    {
        [$user, $laboratory, $branch, $patient, , , $priceList] = $this->activeContext();
        $this->assertFalse($priceList->is_default);

        $response = $this->request($user, $laboratory, $this->payload(
            $branch,
            $patient,
            $priceList,
            null,
            null,
            ['notes' => null],
        ))->assertCreated();

        $order = LaboratoryOrder::query()->sole();
        $this->assertNull($order->doctor_id);
        $this->assertNull($order->commercial_client_id);
        $this->assertNull($order->commercial_client_name);
        $this->assertNull($order->commercial_client_type);
        $response
            ->assertJsonPath('data.doctor', null)
            ->assertJsonPath('data.commercial_client', null)
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.price_list.id', $priceList->id);
    }

    public function test_explicit_price_list_override_ignores_commercial_assignment_and_resolver(): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $selected] = $this->activeContext();
        $assigned = PriceList::factory()->inactive()->for($laboratory)->create(['currency' => 'USD']);
        CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $assigned->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->request($user, $laboratory, $this->payload($branch, $patient, $selected, $doctor, $client))
            ->assertCreated()
            ->assertJsonPath('data.price_list.id', $selected->id)
            ->assertJsonPath('data.currency', $selected->currency);

        $order = LaboratoryOrder::query()->sole();
        $this->assertSame($selected->id, $order->price_list_id);
        $this->assertSame($selected->currency, $order->currency);
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'commercial_client_price_lists')));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'price_list_exams')));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'laboratory_order_exams')));
    }

    public function test_null_optional_references_skip_their_domain_queries_and_only_one_order_is_inserted(): void
    {
        [$user, $laboratory, $branch, $patient, , , $priceList] = $this->activeContext();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->request($user, $laboratory, $this->payload($branch, $patient, $priceList))
            ->assertCreated();

        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "doctors"')));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "commercial_clients"')));
        $this->assertCount(1, collect($queries)->filter(
            fn (string $sql): bool => str_starts_with($sql, 'insert into "laboratory_orders"'),
        ));
        $this->assertCount(0, collect($queries)->filter(fn (string $sql): bool => str_starts_with($sql, 'update ')));
    }

    public function test_multiple_rapid_orders_receive_distinct_opaque_codes(): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->activeContext();
        $payload = $this->payload($branch, $patient, $priceList, $doctor, $client);

        $this->request($user, $laboratory, $payload)->assertCreated();
        $this->request($user, $laboratory, $payload)->assertCreated();

        $codes = LaboratoryOrder::query()->pluck('code');
        $this->assertCount(2, $codes);
        $this->assertCount(2, $codes->unique());
    }

    public function test_currency_is_a_persisted_snapshot(): void
    {
        [$user, $laboratory, $branch, $patient, , , $priceList] = $this->activeContext();
        $priceList->update(['currency' => 'USD']);
        $this->request($user, $laboratory, $this->payload($branch, $patient, $priceList))->assertCreated();

        $priceList->update(['currency' => 'EUR']);

        $this->assertSame('USD', LaboratoryOrder::query()->sole()->currency);
    }

    public function test_creation_does_not_mutate_any_reference(): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->activeContext();
        $references = [$laboratory, $branch, $patient, $doctor, $client, $priceList, $user];
        $before = collect($references)->mapWithKeys(fn ($model): array => [
            $model::class.'-'.$model->id => $model->getRawOriginal('updated_at'),
        ]);

        $this->request($user, $laboratory, $this->payload($branch, $patient, $priceList, $doctor, $client))
            ->assertCreated();

        foreach ($references as $reference) {
            $this->assertSame(
                $before[$reference::class.'-'.$reference->id],
                $reference->newQuery()->findOrFail($reference->id)->getRawOriginal('updated_at'),
            );
        }
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_ids_require_real_positive_json_integers(string $field, mixed $value): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->activeContext();
        $payload = $this->payload($branch, $patient, $priceList, $doctor, $client);
        $payload[$field] = $value;

        $this->request($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidIdentifierProvider(): array
    {
        $cases = [];

        foreach (['branch_id', 'patient_id', 'doctor_id', 'commercial_client_id', 'price_list_id'] as $field) {
            foreach ([
                'numeric string' => '1',
                'zero' => 0,
                'negative' => -1,
                'decimal' => 1.5,
                'true' => true,
                'false' => false,
                'array' => [1],
                'object' => (object) ['id' => 1],
            ] as $case => $value) {
                $cases["{$field} {$case}"] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('missingFieldProvider')]
    public function test_every_input_key_is_required(string $field): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->activeContext();
        $payload = $this->payload($branch, $patient, $priceList, $doctor, $client);
        unset($payload[$field]);

        $this->request($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    /** @return array<string, array{string}> */
    public static function missingFieldProvider(): array
    {
        return collect([
            'branch_id', 'patient_id', 'doctor_id', 'commercial_client_id',
            'price_list_id', 'ordered_at', 'notes',
        ])->mapWithKeys(fn (string $field): array => [$field => [$field]])->all();
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_server_owned_future_and_unknown_fields_are_rejected(string $field): void
    {
        [$user, $laboratory, $branch, $patient, , , $priceList] = $this->activeContext();
        $payload = $this->payload($branch, $patient, $priceList) + [$field => 'injected'];

        $this->request($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return collect([
            'id', 'laboratory_id', 'code', 'status', 'subtotal', 'discount_type',
            'discount_value', 'discount',
            'taxes', 'total', 'currency', 'created_by', 'created_at', 'updated_at',
            'exam_ids', 'exams', 'order_exams', 'items', 'discount_percentage',
            'tax_percentage', 'doctor_name', 'patient_name', 'commercial_client_name',
            'commercial_client_type',
            'price_list_name', 'branch_name', 'foo',
        ])->mapWithKeys(fn (string $field): array => [$field => [$field]])->all();
    }

    #[DataProvider('invalidDateTimeProvider')]
    public function test_ordered_at_is_strict(string|int|bool|array $value): void
    {
        [$user, $laboratory, $branch, $patient, , , $priceList] = $this->activeContext();

        $this->request($user, $laboratory, $this->payload($branch, $patient, $priceList, null, null, [
            'ordered_at' => $value,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['ordered_at']);
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    /** @return array<string, array{string|int|bool|array}> */
    public static function invalidDateTimeProvider(): array
    {
        return [
            'slash' => ['2026/10/03 14:30'],
            'day first' => ['03-10-2026'],
            'date only' => ['2026-10-03'],
            'iso' => ['2026-10-03T14:30:00Z'],
            'missing seconds' => ['2026-10-03 14:30'],
            'impossible date' => ['2026-02-30 14:30:00'],
            'natural language' => ['today'],
            'integer' => [20261003],
            'boolean' => [true],
            'array' => [['2026-10-03 14:30:00']],
        ];
    }

    public function test_non_string_notes_are_rejected(): void
    {
        [$user, $laboratory, $branch, $patient, , , $priceList] = $this->activeContext();

        foreach ([123, true, ['nota'], (object) ['note' => 'x']] as $notes) {
            $this->request($user, $laboratory, $this->payload($branch, $patient, $priceList, null, null, [
                'notes' => $notes,
            ]))->assertUnprocessable()->assertJsonValidationErrors(['notes']);
        }

        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    #[DataProvider('invalidReferenceProvider')]
    public function test_cross_tenant_nonexistent_and_inactive_references_share_422_contract(string $field): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->activeContext();
        [, $foreignLaboratory, $foreignBranch, $foreignPatient, $foreignDoctor, $foreignClient, $foreignPriceList] = $this->activeContext($user);
        $local = compact('branch', 'patient', 'doctor', 'client', 'priceList');
        $foreign = [
            'branch_id' => $foreignBranch,
            'patient_id' => $foreignPatient,
            'doctor_id' => $foreignDoctor,
            'commercial_client_id' => $foreignClient,
            'price_list_id' => $foreignPriceList,
        ];
        $payload = $this->payload($local['branch'], $local['patient'], $local['priceList'], $local['doctor'], $local['client']);

        $missing = $this->request($user, $laboratory, array_replace($payload, [$field => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $crossTenant = $this->request($user, $laboratory, array_replace($payload, [$field => $foreign[$field]->id]))
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame($missing->json('errors'), $crossTenant->json('errors'));
        $this->assertSame($foreignLaboratory->id, $foreign[$field]->laboratory_id);
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    /** @return array<string, array{string}> */
    public static function invalidReferenceProvider(): array
    {
        return [
            'branch' => ['branch_id'],
            'patient' => ['patient_id'],
            'doctor' => ['doctor_id'],
            'commercial client' => ['commercial_client_id'],
            'price list' => ['price_list_id'],
        ];
    }

    #[DataProvider('inactiveReferenceProvider')]
    public function test_inactive_references_are_rejected_atomically(string $field, string $table): void
    {
        [$user, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->activeContext();
        $model = [
            'branch_id' => $branch,
            'patient_id' => $patient,
            'doctor_id' => $doctor,
            'commercial_client_id' => $client,
            'price_list_id' => $priceList,
        ][$field];
        DB::table($table)->where('id', $model->id)->update(['status' => 'inactive']);

        $this->request($user, $laboratory, $this->payload($branch, $patient, $priceList, $doctor, $client))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function inactiveReferenceProvider(): array
    {
        return [
            'branch' => ['branch_id', 'branches'],
            'patient' => ['patient_id', 'patients'],
            'doctor' => ['doctor_id', 'doctors'],
            'commercial client' => ['commercial_client_id', 'commercial_clients'],
            'price list' => ['price_list_id', 'price_lists'],
        ];
    }

    public function test_current_laboratory_and_authenticated_creator_are_selected_only_from_pipeline(): void
    {
        $user = User::factory()->create();
        [$user, $laboratoryA, $branchA, $patientA, , , $priceListA] = $this->activeContext($user);
        [$user, $laboratoryB, $branchB, $patientB, , , $priceListB] = $this->activeContext($user);

        $this->request($user, $laboratoryA, $this->payload($branchA, $patientA, $priceListA))->assertCreated();
        $this->request($user, $laboratoryB, $this->payload($branchB, $patientB, $priceListB))->assertCreated();

        $this->assertSame([$laboratoryA->id, $laboratoryB->id], LaboratoryOrder::query()->orderBy('id')->pluck('laboratory_id')->all());
        $this->assertSame([$user->id, $user->id], LaboratoryOrder::query()->orderBy('id')->pluck('created_by')->all());
    }

    public function test_saas_pipeline_precedes_payload_validation(): void
    {
        $this->postJson('/api/v1/laboratory-orders', [])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/api/v1/laboratory-orders', [])
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $laboratory = Laboratory::factory()->create();
        $this->request($user, $laboratory, [])->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->request($user, $laboratory, [])->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    public function test_route_controller_and_openapi_preserve_the_store_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-orders'))
            ->values();

        $this->assertCount(8, $routes);
        $this->assertSame([['POST'], ['GET', 'HEAD'], ['POST'], ['DELETE'], ['PUT'], ['DELETE'], ['PATCH'], ['GET', 'HEAD']], $routes->map(fn ($route): array => $route->methods())->all());
        foreach ($routes as $route) {
            $this->assertContains('saas', $route->middleware());
        }
        $this->assertSame(['addExam', 'listExams', 'removeDiscount', 'removeExam', 'show', 'store', 'updateDiscount', 'updateStatus'], collect((new ReflectionClass(LaboratoryOrderController::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LaboratoryOrderController::class)
            ->pluck('name')->sort()->values()->all());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/laboratory-orders']['post'];
        $input = $document['components']['schemas']['CreateLaboratoryOrderInput'];
        $commercialClientOutput = $document['components']['schemas']['LaboratoryOrderCommercialClient'];
        $priceListOutput = $document['components']['schemas']['LaboratoryOrderPriceList'];
        $orderOutput = $document['components']['schemas']['LaboratoryOrder'];
        $operationCount = collect($document['paths'])->sum(fn (array $path): int => count(array_intersect_key(
            $path,
            array_flip(['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']),
        )));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(66, $operationCount);
        // The test bootstrap omits Laravel's generated storage route; the real
        // CLI inventory is asserted separately and contains one additional route.
        $this->assertCount(74, Route::getRoutes()->getRoutes());
        $this->assertSame([
            'branch_id', 'patient_id', 'doctor_id', 'commercial_client_id',
            'price_list_id', 'ordered_at', 'notes',
        ], $input['required']);
        $this->assertSame($input['required'], array_keys($input['properties']));
        $this->assertFalse($input['additionalProperties']);
        $this->assertSame(['integer', 'null'], $input['properties']['doctor_id']['type']);
        $this->assertSame(['integer', 'null'], $input['properties']['commercial_client_id']['type']);
        $this->assertSame(['string', 'null'], $input['properties']['notes']['type']);
        $this->assertStringContainsString('histórico', $commercialClientOutput['properties']['name']['description']);
        $this->assertStringContainsString('histórico', $commercialClientOutput['properties']['type']['description']);
        $this->assertStringContainsString('histórico', $priceListOutput['properties']['name']['description']);
        $this->assertStringContainsString('histórica', $priceListOutput['properties']['currency']['description']);
        $this->assertContains('discount_type', $orderOutput['required']);
        $this->assertContains('discount_value', $orderOutput['required']);
        $this->assertSame(['string', 'null'], $orderOutput['properties']['discount_type']['type']);
        $this->assertSame(['percentage', 'amount', null], $orderOutput['properties']['discount_type']['enum']);
        $this->assertSame(['string', 'null'], $orderOutput['properties']['discount_value']['type']);
        $this->assertStringContainsString('10.00 para 10%', $orderOutput['properties']['discount_value']['description']);
        $this->assertStringContainsString('Monto monetario resultante', $orderOutput['properties']['discount']['description']);
        $this->assertSame('#/components/schemas/CreateLaboratoryOrderInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/LaboratoryOrderResponse', $operation['responses']['201']['content']['application/json']['schema']['$ref']);
        $this->assertSame([201, 400, 401, 403, 422], array_keys($operation['responses']));
    }

    /** @return array{User, Laboratory, Branch, Patient, Doctor, CommercialClient, PriceList} */
    private function activeContext(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [
            $user,
            $laboratory,
            Branch::factory()->for($laboratory)->create(),
            Patient::factory()->for($laboratory)->create(),
            Doctor::factory()->for($laboratory)->create(),
            CommercialClient::factory()->for($laboratory)->create(),
            PriceList::factory()->for($laboratory)->create(['currency' => 'GTQ']),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(
        Branch $branch,
        Patient $patient,
        PriceList $priceList,
        ?Doctor $doctor = null,
        ?CommercialClient $commercialClient = null,
        array $overrides = [],
    ): array {
        return array_replace([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor?->id,
            'commercial_client_id' => $commercialClient?->id,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-03 14:30:00',
            'notes' => 'Observaciones opcionales',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function request(User $user, Laboratory $laboratory, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-orders', $payload);
    }
}
