<?php

namespace Tests\Feature\Api\V1\Dashboard;

use App\Audit\OrderAuditEvents;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DashboardShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    }

    public function test_returns_daily_metrics_using_the_laboratory_timezone_and_business_definitions(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patientA = Patient::factory()->for($laboratory)->create();
        $patientB = Patient::factory()->for($laboratory)->create();
        $patientC = Patient::factory()->for($laboratory)->create();

        $this->order($user, $laboratory, [
            'patient_id' => $patientA->id,
            'ordered_at' => '2026-10-08 06:00:00',
            'status' => LaboratoryOrder::STATUS_PENDING,
        ]);
        $this->order($user, $laboratory, [
            'patient_id' => $patientA->id,
            'ordered_at' => '2026-10-08 12:00:00',
            'status' => LaboratoryOrder::STATUS_PENDING,
        ]);
        $this->order($user, $laboratory, [
            'patient_id' => $patientB->id,
            'ordered_at' => '2026-10-08 18:00:00',
            'status' => LaboratoryOrder::STATUS_COMPLETED,
        ]);
        $this->order($user, $laboratory, [
            'patient_id' => $patientC->id,
            'ordered_at' => '2026-10-09 05:59:59',
            'status' => LaboratoryOrder::STATUS_CANCELLED,
        ]);
        $this->order($user, $laboratory, [
            'patient_id' => $patientA->id,
            'ordered_at' => '2026-10-07 06:00:00',
            'status' => LaboratoryOrder::STATUS_COMPLETED,
        ]);
        $this->order($user, $laboratory, [
            'patient_id' => $patientC->id,
            'ordered_at' => '2026-10-08 05:59:59',
            'status' => LaboratoryOrder::STATUS_CANCELLED,
        ]);
        $this->order($user, $laboratory, [
            'patient_id' => $patientC->id,
            'ordered_at' => '2026-10-09 06:00:00',
            'status' => LaboratoryOrder::STATUS_PENDING,
        ]);

        $response = $this->dashboardRequest($user, $laboratory)->assertOk();

        $response
            ->assertJsonPath('data.context.date', '2026-10-08')
            ->assertJsonPath('data.context.timezone', 'America/Guatemala')
            ->assertJsonPath('data.context.currency', 'GTQ')
            ->assertJsonPath('data.context.generated_at', '2026-10-08T06:00:00-06:00')
            ->assertJsonPath('data.metrics.orders.count', 4)
            ->assertJsonPath('data.metrics.orders.previous_count', 2)
            ->assertJsonPath('data.metrics.orders.change_percentage', 100)
            ->assertJsonPath('data.metrics.attended_patients.count', 2)
            ->assertJsonPath('data.metrics.attended_patients.previous_count', 1)
            ->assertJsonPath('data.metrics.attended_patients.change_percentage', 100)
            ->assertJsonPath('data.metrics.pending_orders.count', 2)
            ->assertJsonPath('data.recent_activity', []);

        $this->assertSame(
            ['context', 'metrics', 'recent_activity'],
            array_keys($response->json('data')),
        );
        $this->assertSame(
            ['orders', 'attended_patients', 'pending_orders'],
            array_keys($response->json('data.metrics')),
        );
    }

    public function test_returns_null_change_percentage_when_the_previous_day_is_zero(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->order($user, $laboratory, [
            'ordered_at' => '2026-10-08 14:00:00',
        ]);

        $this->dashboardRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.metrics.orders.count', 1)
            ->assertJsonPath('data.metrics.orders.previous_count', 0)
            ->assertJsonPath('data.metrics.orders.change_percentage', null)
            ->assertJsonPath('data.metrics.attended_patients.change_percentage', null);
    }

    public function test_explicit_date_returns_the_requested_historical_business_day(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->order($user, $laboratory, [
            'ordered_at' => '2026-10-07 12:00:00',
        ]);
        $this->order($user, $laboratory, [
            'ordered_at' => '2026-10-08 12:00:00',
        ]);

        $this->dashboardRequest($user, $laboratory, ['date' => '2026-10-07'])
            ->assertOk()
            ->assertJsonPath('data.context.date', '2026-10-07')
            ->assertJsonPath('data.metrics.orders.count', 1);
    }

    public function test_returns_the_four_latest_supported_activities_with_patient_and_order_data(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = Patient::factory()->for($laboratory)->create([
            'first_names' => 'María',
            'last_names' => 'Rodríguez',
        ]);
        $order = $this->order($user, $laboratory, [
            'patient_id' => $patient->id,
            'code' => 'ORD-1028',
            'ordered_at' => '2026-10-08 14:00:00',
            'status' => LaboratoryOrder::STATUS_IN_PROCESS,
        ]);
        $supportedEvents = [];
        foreach (range(7, 11) as $hour) {
            $supportedEvents[] = AuditLog::factory()->for($laboratory)->create([
                'user_id' => $user->id,
                'event' => $hour % 2 === 0
                    ? OrderAuditEvents::STATUS_CHANGED
                    : OrderAuditEvents::CREATED,
                'auditable_type' => OrderAuditEvents::SUBJECT_ORDER,
                'auditable_id' => $order->id,
                'created_at' => "2026-10-08 {$hour}:00:00",
            ]);
        }
        AuditLog::factory()->for($laboratory)->create([
            'user_id' => $user->id,
            'event' => OrderAuditEvents::DISCOUNT_SET,
            'auditable_type' => OrderAuditEvents::SUBJECT_ORDER,
            'auditable_id' => $order->id,
            'created_at' => '2026-10-08 11:30:00',
        ]);
        AuditLog::factory()->for($laboratory)->create([
            'user_id' => $user->id,
            'event' => OrderAuditEvents::CREATED,
            'auditable_type' => OrderAuditEvents::SUBJECT_ORDER,
            'auditable_id' => $order->id,
            'created_at' => '2026-10-08 05:59:59',
        ]);

        $response = $this->dashboardRequest($user, $laboratory)->assertOk();

        $this->assertSame(
            collect($supportedEvents)->reverse()->take(4)->pluck('id')->all(),
            $response->json('data.recent_activity.*.id'),
        );
        $response
            ->assertJsonCount(4, 'data.recent_activity')
            ->assertJsonPath('data.recent_activity.0.occurred_at', '2026-10-08T05:00:00-06:00')
            ->assertJsonPath('data.recent_activity.0.patient.id', $patient->id)
            ->assertJsonPath('data.recent_activity.0.patient.first_names', 'María')
            ->assertJsonPath('data.recent_activity.0.patient.last_names', 'Rodríguez')
            ->assertJsonPath('data.recent_activity.0.order.id', $order->id)
            ->assertJsonPath('data.recent_activity.0.order.code', 'ORD-1028')
            ->assertJsonPath('data.recent_activity.0.order.status', LaboratoryOrder::STATUS_IN_PROCESS);
    }

    public function test_branch_filter_scopes_metrics_and_activity_and_rejects_another_tenant_branch(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $branchA = Branch::factory()->for($laboratory)->create();
        $branchB = Branch::factory()->for($laboratory)->create();
        $orderA = $this->order($user, $laboratory, [
            'branch_id' => $branchA->id,
            'ordered_at' => '2026-10-08 14:00:00',
        ]);
        $orderB = $this->order($user, $laboratory, [
            'branch_id' => $branchB->id,
            'ordered_at' => '2026-10-08 15:00:00',
        ]);
        $activityA = $this->activity($user, $laboratory, $orderA, '2026-10-08 14:01:00');
        $this->activity($user, $laboratory, $orderB, '2026-10-08 15:01:00');

        $this->dashboardRequest($user, $laboratory, ['branch_id' => $branchA->id])
            ->assertOk()
            ->assertJsonPath('data.metrics.orders.count', 1)
            ->assertJsonPath('data.recent_activity.*.id', [$activityA->id]);

        $otherLaboratory = Laboratory::factory()->create();
        $foreignBranch = Branch::factory()->for($otherLaboratory)->create();
        $this->dashboardRequest($user, $laboratory, ['branch_id' => $foreignBranch->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_and_unknown_query_parameters_return_422(array $query, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->dashboardRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueryProvider(): array
    {
        return [
            'impossible date' => [['date' => '2026-02-30'], 'date'],
            'date with time' => [['date' => '2026-10-08 00:00:00'], 'date'],
            'branch zero' => [['branch_id' => 0], 'branch_id'],
            'tenant injection' => [['laboratory_id' => 1], 'laboratory_id'],
            'laboratory alias' => [['laboratory' => 1], 'laboratory'],
            'unknown' => [['foo' => 'bar'], 'foo'],
        ];
    }

    public function test_saas_pipeline_and_orders_view_permission_protect_the_endpoint(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')
            ->getJson('/api/v1/dashboard')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        $this->dashboardRequest($user, $laboratory)->assertForbidden();

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'orders.view');
        $this->dashboardRequest($user, $laboratory)->assertOk();
    }

    public function test_selected_laboratory_isolates_metrics_and_activity_for_multi_membership_users(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $orderA = $this->order($user, $laboratoryA, ['ordered_at' => '2026-10-08 14:00:00']);
        $orderB = $this->order($user, $laboratoryB, ['ordered_at' => '2026-10-08 14:00:00']);
        $activityA = $this->activity($user, $laboratoryA, $orderA, '2026-10-08 14:01:00');
        $activityB = $this->activity($user, $laboratoryB, $orderB, '2026-10-08 14:01:00');

        $this->dashboardRequest($user, $laboratoryA)
            ->assertOk()
            ->assertJsonPath('data.metrics.orders.count', 1)
            ->assertJsonPath('data.recent_activity.*.id', [$activityA->id]);
        $this->dashboardRequest($user, $laboratoryB)
            ->assertOk()
            ->assertJsonPath('data.metrics.orders.count', 1)
            ->assertJsonPath('data.recent_activity.*.id', [$activityB->id]);
    }

    public function test_route_and_openapi_publish_the_dashboard_contract(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/dashboard'
                && in_array('GET', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertSame(DashboardController::class, $route->getActionName());
        $this->assertContains('saas', $route->gatherMiddleware());
        $this->assertContains('can:orders.view', $route->gatherMiddleware());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $operation = $document['paths']['/api/v1/dashboard']['get'];
        $parameters = collect($operation['parameters']);

        $this->assertEqualsCanonicalizing(
            ['date', 'branch_id'],
            $parameters->pluck('name')->filter()->values()->all(),
        );
        $this->assertTrue($parameters->contains(
            fn (array $parameter): bool => ($parameter['$ref'] ?? null) === '#/components/parameters/LaboratoryContextHeader',
        ));
        $this->assertSame(
            '#/components/schemas/DashboardResponse',
            $operation['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create([
            'timezone' => 'America/Guatemala',
            'currency' => 'GTQ',
        ]);
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'orders.view');

        return [$user, $laboratory];
    }

    private function createCurrentSubscription(Laboratory $laboratory): Subscription
    {
        return Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function order(User $user, Laboratory $laboratory, array $attributes = []): LaboratoryOrder
    {
        return LaboratoryOrder::factory()->for($laboratory)->create(array_replace([
            'created_by' => $user->id,
        ], $attributes));
    }

    private function activity(
        User $user,
        Laboratory $laboratory,
        LaboratoryOrder $order,
        string $createdAt,
    ): AuditLog {
        return AuditLog::factory()->for($laboratory)->create([
            'user_id' => $user->id,
            'event' => OrderAuditEvents::CREATED,
            'auditable_type' => OrderAuditEvents::SUBJECT_ORDER,
            'auditable_id' => $order->id,
            'created_at' => $createdAt,
        ]);
    }

    /** @param array<string, mixed> $query */
    private function dashboardRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/dashboard';
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}
