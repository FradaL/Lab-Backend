<?php

namespace Tests\Feature\Api\V1\Pricing;

use App\Http\Controllers\Api\V1\PricingResolutionController;
use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Pricing\AmbiguousEffectivePriceListException;
use App\Services\Pricing\PriceListResolution;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class EffectivePriceListResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_particular_context_resolves_only_the_active_default_price_list(): void
    {
        [$user, $laboratory] = $this->activeContext();
        PriceList::factory()->for($laboratory)->create(['name' => 'Lista no predeterminada']);
        $default = PriceList::factory()->asDefault()->for($laboratory)->create([
            'name' => 'Tarifa General Configurada',
            'currency' => 'GTQ',
        ]);

        $response = $this->request($user, $laboratory, null, '2026-10-03')->assertOk();

        $this->assertSame([
            'context', 'commercial_client', 'effective_date', 'resolved', 'reason',
            'price_list', 'assignment',
        ], array_keys($response->json('data')));
        $response
            ->assertJsonPath('data.context', PriceListResolution::CONTEXT_PARTICULAR)
            ->assertJsonPath('data.commercial_client', null)
            ->assertJsonPath('data.effective_date', '2026-10-03')
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.price_list.id', $default->id)
            ->assertJsonPath('data.price_list.name', 'Tarifa General Configurada')
            ->assertJsonPath('data.price_list.currency', 'GTQ')
            ->assertJsonPath('data.price_list.is_default', true)
            ->assertJsonPath('data.assignment', null);
        $this->assertArrayNotHasKey('laboratory_id', $response->json('data'));
        $this->assertSame(['id', 'name', 'currency', 'is_default'], array_keys($response->json('data.price_list')));
    }

    #[DataProvider('unresolvedParticularProvider')]
    public function test_particular_context_is_unresolved_without_an_active_default(string $configuration): void
    {
        [$user, $laboratory] = $this->activeContext();

        if ($configuration === 'inactive default') {
            PriceList::factory()->inactive()->asDefault()->for($laboratory)->create();
        } elseif ($configuration === 'active nondefault') {
            PriceList::factory()->for($laboratory)->create();
        }

        $this->request($user, $laboratory, null, '2026-10-03')
            ->assertOk()
            ->assertExactJson(['data' => [
                'context' => PriceListResolution::CONTEXT_PARTICULAR,
                'commercial_client' => null,
                'effective_date' => '2026-10-03',
                'resolved' => false,
                'reason' => PriceListResolution::REASON_NO_DEFAULT_PRICE_LIST,
                'price_list' => null,
                'assignment' => null,
            ]]);
    }

    /** @return array<string, array{string}> */
    public static function unresolvedParticularProvider(): array
    {
        return [
            'no price lists' => ['none'],
            'legacy inactive default' => ['inactive default'],
            'active list is not default' => ['active nondefault'],
        ];
    }

    public function test_commercial_context_resolves_the_active_effective_assignment(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->insurance()->for($laboratory)->create(['name' => 'Seguros XYZ']);
        $priceList = PriceList::factory()->asDefault()->for($laboratory)->create([
            'name' => 'Convenio Seguros XYZ 2026',
        ]);
        $assignment = $this->assignment($laboratory, $client, $priceList, '2026-01-01', '2026-12-31');

        $response = $this->request($user, $laboratory, $client->id, '2026-10-03')->assertOk();

        $response->assertExactJson(['data' => [
            'context' => PriceListResolution::CONTEXT_COMMERCIAL_CLIENT,
            'commercial_client' => [
                'id' => $client->id,
                'name' => 'Seguros XYZ',
                'type' => CommercialClient::TYPE_INSURANCE,
            ],
            'effective_date' => '2026-10-03',
            'resolved' => true,
            'reason' => null,
            'price_list' => [
                'id' => $priceList->id,
                'name' => 'Convenio Seguros XYZ 2026',
                'currency' => 'GTQ',
                'is_default' => true,
            ],
            'assignment' => [
                'id' => $assignment->id,
                'starts_at' => '2026-01-01',
                'ends_at' => '2026-12-31',
            ],
        ]]);
        $this->assertStringNotContainsString('laboratory_id', $response->getContent());
    }

    public function test_nonexistent_cross_tenant_and_inactive_clients_share_the_same_validation_error(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $foreignClient = CommercialClient::factory()->create();
        $inactiveClient = CommercialClient::factory()->inactive()->for($laboratory)->create();

        foreach ([999999, $foreignClient->id, $inactiveClient->id] as $clientId) {
            $this->request($user, $laboratory, $clientId, '2026-10-03')
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['commercial_client_id'])
                ->assertJsonPath('errors.commercial_client_id.0', 'La entidad comercial seleccionada no es válida.');
        }
    }

    #[DataProvider('noEffectiveAssignmentProvider')]
    public function test_non_effective_assignments_are_not_selected(string $case): void
    {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();

        if ($case === 'inactive') {
            $this->assignment($laboratory, $client, $priceList, '2026-01-01', null, CommercialClientPriceList::STATUS_INACTIVE);
        } elseif ($case === 'future') {
            $this->assignment($laboratory, $client, $priceList, '2027-01-01', null);
        } elseif ($case === 'expired') {
            $this->assignment($laboratory, $client, $priceList, '2025-01-01', '2025-12-31');
        } else {
            $this->assignment($laboratory, $client, $priceList, '2025-01-01', '2025-12-31');
            $futurePriceList = PriceList::factory()->for($laboratory)->create();
            $this->assignment($laboratory, $client, $futurePriceList, '2027-01-01', null);
        }

        $this->request($user, $laboratory, $client->id, '2026-10-03')
            ->assertOk()
            ->assertExactJson(['data' => [
                'context' => PriceListResolution::CONTEXT_COMMERCIAL_CLIENT,
                'commercial_client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'type' => $client->type,
                ],
                'effective_date' => '2026-10-03',
                'resolved' => false,
                'reason' => PriceListResolution::REASON_NO_EFFECTIVE_PRICE_LIST,
                'price_list' => null,
                'assignment' => null,
            ]]);
    }

    /** @return array<string, array{string}> */
    public static function noEffectiveAssignmentProvider(): array
    {
        return [
            'inactive assignment' => ['inactive'],
            'future assignment' => ['future'],
            'expired assignment' => ['expired'],
            'gap is not interpolated' => ['gap'],
        ];
    }

    #[DataProvider('inclusiveBoundaryProvider')]
    public function test_start_end_and_open_ended_boundaries_are_inclusive(
        string $startsAt,
        ?string $endsAt,
        string $effectiveDate,
    ): void {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $this->assignment($laboratory, $client, $priceList, $startsAt, $endsAt);

        $this->request($user, $laboratory, $client->id, $effectiveDate)
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.price_list.id', $priceList->id);
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function inclusiveBoundaryProvider(): array
    {
        return [
            'start boundary' => ['2026-10-03', '2026-12-31', '2026-10-03'],
            'end boundary' => ['2026-01-01', '2026-10-03', '2026-10-03'],
            'open ended future date' => ['2026-01-01', null, '2030-12-31'],
        ];
    }

    public function test_inactive_assigned_price_list_returns_diagnostic_assignment_without_fallback(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        PriceList::factory()->asDefault()->for($laboratory)->create();
        $inactivePriceList = PriceList::factory()->inactive()->for($laboratory)->create();
        $assignment = $this->assignment($laboratory, $client, $inactivePriceList, '2026-01-01', null);

        $this->request($user, $laboratory, $client->id, '2026-10-03')
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.reason', PriceListResolution::REASON_ASSIGNED_PRICE_LIST_INACTIVE)
            ->assertJsonPath('data.price_list', null)
            ->assertJsonPath('data.assignment.id', $assignment->id)
            ->assertJsonPath('data.assignment.starts_at', '2026-01-01')
            ->assertJsonPath('data.assignment.ends_at', null);

        $this->assertSame(PriceList::STATUS_INACTIVE, $inactivePriceList->fresh()->status);
    }

    public function test_commercial_context_never_falls_back_to_the_particular_default(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        PriceList::factory()->asDefault()->for($laboratory)->create();

        $this->request($user, $laboratory, $client->id, '2026-10-03')
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.reason', PriceListResolution::REASON_NO_EFFECTIVE_PRICE_LIST)
            ->assertJsonPath('data.price_list', null);
    }

    public function test_assignments_for_other_clients_and_laboratories_are_ignored(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $targetClient = CommercialClient::factory()->for($laboratory)->create();
        $otherClient = CommercialClient::factory()->for($laboratory)->create();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $this->assignment($laboratory, $otherClient, $otherPriceList, '2026-01-01', null);

        $foreignLaboratory = Laboratory::factory()->create();
        $foreignClient = CommercialClient::factory()->for($foreignLaboratory)->create();
        $foreignPriceList = PriceList::factory()->for($foreignLaboratory)->create();
        $this->assignment($foreignLaboratory, $foreignClient, $foreignPriceList, '2026-01-01', null);

        $this->request($user, $laboratory, $targetClient->id, '2026-10-03')
            ->assertOk()
            ->assertJsonPath('data.reason', PriceListResolution::REASON_NO_EFFECTIVE_PRICE_LIST)
            ->assertJsonPath('data.assignment', null);
    }

    public function test_explicit_historical_date_selects_historical_assignment_instead_of_current_one(): void
    {
        $this->travelTo('2026-10-03');
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $historical = PriceList::factory()->for($laboratory)->create(['name' => 'Histórica']);
        $current = PriceList::factory()->for($laboratory)->create(['name' => 'Actual']);
        $this->assignment($laboratory, $client, $historical, '2025-01-01', '2025-12-31');
        $this->assignment($laboratory, $client, $current, '2026-01-01', null);

        $this->request($user, $laboratory, $client->id, '2025-06-15')
            ->assertOk()
            ->assertJsonPath('data.price_list.id', $historical->id)
            ->assertJsonPath('data.effective_date', '2025-06-15');
    }

    public function test_explicit_future_date_selects_a_scheduled_assignment(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $future = PriceList::factory()->for($laboratory)->create();
        $this->assignment($laboratory, $client, $future, '2028-01-01', null);

        $this->request($user, $laboratory, $client->id, '2028-06-15')
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.price_list.id', $future->id);
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_unknown_fields_are_rejected(string $field): void
    {
        [$user, $laboratory] = $this->activeContext();

        $this->requestPayload($user, $laboratory, [
            'commercial_client_id' => null,
            'effective_date' => '2026-10-03',
            $field => 'injected',
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return collect([
            'laboratory_id', 'price_list_id', 'assignment_id', 'patient_id', 'doctor_id',
            'branch_id', 'order_id', 'status', 'currency', 'is_default', 'exam_id',
            'foo', 'created_at', 'updated_at',
        ])->mapWithKeys(fn (string $field): array => [$field => [$field]])->all();
    }

    public function test_commercial_client_key_is_required_but_explicit_null_is_particular(): void
    {
        [$user, $laboratory] = $this->activeContext();
        PriceList::factory()->asDefault()->for($laboratory)->create();

        $this->requestPayload($user, $laboratory, ['effective_date' => '2026-10-03'])
            ->assertUnprocessable()->assertJsonValidationErrors(['commercial_client_id']);
        $this->request($user, $laboratory, null, '2026-10-03')
            ->assertOk()->assertJsonPath('data.context', PriceListResolution::CONTEXT_PARTICULAR);
    }

    public function test_effective_date_key_is_required(): void
    {
        [$user, $laboratory] = $this->activeContext();

        $this->requestPayload($user, $laboratory, ['commercial_client_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['effective_date']);
    }

    #[DataProvider('invalidCommercialClientIdProvider')]
    public function test_commercial_client_id_requires_null_or_a_positive_json_integer(mixed $value): void
    {
        [$user, $laboratory] = $this->activeContext();

        $this->requestPayload($user, $laboratory, [
            'commercial_client_id' => $value,
            'effective_date' => '2026-10-03',
        ])->assertUnprocessable()->assertJsonValidationErrors(['commercial_client_id']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidCommercialClientIdProvider(): array
    {
        return [
            'numeric string' => ['123'],
            'zero' => [0],
            'negative' => [-1],
            'float' => [1.5],
            'true' => [true],
            'false' => [false],
            'array' => [[]],
            'object' => [(object) ['id' => 1]],
            'particular string' => ['particular'],
            'empty string' => [''],
        ];
    }

    #[DataProvider('invalidEffectiveDateProvider')]
    public function test_effective_date_requires_an_exact_valid_json_date(mixed $value): void
    {
        [$user, $laboratory] = $this->activeContext();

        $this->requestPayload($user, $laboratory, [
            'commercial_client_id' => null,
            'effective_date' => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['effective_date']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidEffectiveDateProvider(): array
    {
        return [
            'slashes' => ['2026/10/03'],
            'day first' => ['03-10-2026'],
            'single digit day' => ['2026-10-3'],
            'datetime' => ['2026-10-03T00:00:00Z'],
            'timestamp' => [1790985600],
            'today' => ['today'],
            'tomorrow' => ['tomorrow'],
            'array' => [['2026-10-03']],
            'null' => [null],
            'invalid calendar date' => ['2026-02-29'],
        ];
    }

    public function test_resolved_and_unresolved_flows_are_read_only(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $default = PriceList::factory()->asDefault()->for($laboratory)->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $before = $this->domainSnapshot();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->request($user, $laboratory, null, '2026-10-03')->assertOk()
            ->assertJsonPath('data.price_list.id', $default->id);
        $this->request($user, $laboratory, $client->id, '2026-10-03')->assertOk()
            ->assertJsonPath('data.resolved', false);

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_particular_query_plan_has_no_client_assignment_or_unrelated_queries(): void
    {
        [$user, $laboratory] = $this->activeContext();
        PriceList::factory()->asDefault()->for($laboratory)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, null, '2026-10-03')->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "price_lists"')));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'commercial_clients')));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'commercial_client_price_lists')));
        $this->assertNoUnrelatedQueries($queries->all());
    }

    public function test_commercial_query_plan_has_one_client_assignment_and_price_list_query_without_n_plus_one(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $this->assignment($laboratory, $client, $priceList, '2026-01-01', null);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, $client->id, '2026-10-03')->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        $domainQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "commercial_clients"')
            || str_contains($sql, 'from "commercial_client_price_lists"')
            || str_contains($sql, 'from "price_lists"'));
        $this->assertCount(3, $domainQueries);
        $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "commercial_clients"')));
        $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "commercial_client_price_lists"')));
        $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "price_lists"')));
        $this->assertNoUnrelatedQueries($queries->all());
    }

    public function test_ambiguous_effective_data_raises_an_invariant_violation_without_tiebreaking(): void
    {
        [$user, $laboratory] = $this->activeContext();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        $this->assignment($laboratory, $client, $priceLists[0], '2026-01-01', null);
        $this->assignment($laboratory, $client, $priceLists[1], '2026-06-01', '2026-12-31');
        $this->withoutExceptionHandling();

        $this->expectException(AmbiguousEffectivePriceListException::class);
        $this->request($user, $laboratory, $client->id, '2026-10-03');
    }

    public function test_saas_pipeline_precedes_payload_validation(): void
    {
        $url = '/api/v1/pricing/resolve-price-list';
        $payload = ['foo' => 'invalid'];
        $this->postJson($url, $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson($url, $payload)
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')->postJson($url, $payload)
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')->postJson($url, $payload)
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $withoutMembership = Laboratory::factory()->create();
        $this->currentSubscription($withoutMembership);
        $this->requestPayload($user, $withoutMembership, $payload)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        $this->currentSubscription($inactiveMembership);
        $this->requestPayload($user, $inactiveMembership, $payload)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveLaboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLaboratory, ['is_active' => true]);
        $this->currentSubscription($inactiveLaboratory);
        $this->requestPayload($user, $inactiveLaboratory, $payload)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->requestPayload($user, $withoutSubscription, $payload)
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_route_controller_and_openapi_inventories_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $resolverRoutes = $routes->filter(fn ($route): bool => $route->uri() === 'api/v1/pricing/resolve-price-list');

        $this->assertCount(70, $routes);
        $this->assertCount(1, $resolverRoutes);
        $route = $resolverRoutes->first();
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('saas', $route->middleware());
        $methods = collect((new ReflectionClass(PricingResolutionController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PricingResolutionController::class)
            ->pluck('name')->all();
        $this->assertSame(['resolvePriceList'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/pricing/resolve-price-list']['post'];
        $schema = $document['components']['schemas']['ResolvePriceListInput'];
        $responseSchema = $document['components']['schemas']['PriceListResolution'];
        $operations = collect($document['paths'])->flatMap(fn (array $path): array => array_values(array_intersect_key(
            $path,
            array_flip(['get', 'post', 'put', 'patch', 'delete']),
        )));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(62, $operations);
        $this->assertCount(4, $operations->filter(fn (array $item): bool => in_array('Commercial Client Price List Assignments', $item['tags'] ?? [], true)));
        $this->assertCount(6, $operations->filter(fn (array $item): bool => in_array('Commercial Clients', $item['tags'] ?? [], true)));
        $this->assertCount(5, $operations->filter(fn (array $item): bool => in_array('Exam Prices', $item['tags'] ?? [], true)));
        $this->assertSame(['commercial_client_id', 'effective_date'], $schema['required']);
        $this->assertSame(['commercial_client_id', 'effective_date'], array_keys($schema['properties']));
        $this->assertSame(['integer', 'null'], $schema['properties']['commercial_client_id']['type']);
        $this->assertSame(1, $schema['properties']['commercial_client_id']['minimum']);
        $this->assertSame('date', $schema['properties']['effective_date']['format']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame([
            'context', 'commercial_client', 'effective_date', 'resolved', 'reason', 'price_list', 'assignment',
        ], $responseSchema['required']);
        $this->assertSame([200, 400, 401, 403, 422], array_keys($operation['responses']));
    }

    /** @return array{User, Laboratory} */
    private function activeContext(): array
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->currentSubscription($laboratory);

        return [$user, $laboratory];
    }

    private function currentSubscription(Laboratory $laboratory): Subscription
    {
        return Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    private function assignment(
        Laboratory $laboratory,
        CommercialClient $client,
        PriceList $priceList,
        string $startsAt,
        ?string $endsAt,
        string $status = CommercialClientPriceList::STATUS_ACTIVE,
    ): CommercialClientPriceList {
        return CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
        ]);
    }

    private function request(
        User $user,
        Laboratory $laboratory,
        ?int $commercialClientId,
        string $effectiveDate,
    ): TestResponse {
        return $this->requestPayload($user, $laboratory, [
            'commercial_client_id' => $commercialClientId,
            'effective_date' => $effectiveDate,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function requestPayload(User $user, Laboratory $laboratory, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/pricing/resolve-price-list', $payload);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function domainSnapshot(): array
    {
        return collect([
            'commercial_clients',
            'commercial_client_price_lists',
            'price_lists',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ])->all();
    }

    /** @param list<string> $queries */
    private function assertNoUnrelatedQueries(array $queries): void
    {
        foreach (['patients', 'doctors', 'branches', 'orders', 'price_list_exams'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }
}
