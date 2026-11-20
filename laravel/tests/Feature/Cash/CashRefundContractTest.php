<?php

namespace Tests\Feature\Cash;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CashRefundContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_openapi_and_route_match_the_approved_contract(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = '/api/v1/laboratory-orders/{order}/refunds';
        $operation = $document['paths'][$path]['post'];
        $this->assertSame('recordOrderRefund', $operation['operationId']);
        $this->assertSame([['sanctumCookie' => []]], $operation['security']);
        $this->assertSame('#/components/schemas/CashRefundRequest', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/CashRefundResponse', $operation['responses']['201']['content']['application/json']['schema']['$ref']);
        $this->assertSame('boolean', $operation['responses']['201']['headers']['Idempotency-Replayed']['schema']['type']);
        $parameters = collect($operation['parameters']);
        $this->assertSame('^[A-Za-z0-9_.-]{16,100}$', $parameters->firstWhere('name', 'Idempotency-Key')['schema']['pattern']);
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === ltrim($path, '/'));
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('saas', $route->middleware());
        $this->assertContains('can:payments.refund', $route->middleware());
        $schemas = $document['components']['schemas'];
        $request = $schemas['CashRefundRequest'];
        $this->assertFalse($request['additionalProperties']);
        $this->assertEqualsCanonicalizing(['expected_revision', 'amount', 'method', 'reason'], $request['required']);
        $this->assertEqualsCanonicalizing(['expected_revision', 'amount', 'method', 'reference', 'reason'], array_keys($request['properties']));
        $this->assertSame('string', $request['properties']['amount']['type']);
        $this->assertSame(1000, $request['properties']['reason']['maxLength']);
        $this->assertSame(150, $request['properties']['reference']['maxLength']);
        $this->assertSame(['reference'], $request['oneOf'][1]['required']);
        $this->assertSame(['card', 'bank_transfer'], $request['oneOf'][1]['properties']['method']['enum']);
        $movement = $schemas['CashRefundMovement'];
        $this->assertFalse($movement['additionalProperties']);
        $this->assertSame(['refund'], $movement['properties']['type']['enum']);
        $this->assertSame('null', $movement['properties']['target_payment_id']['type']);
        $this->assertSame('null', $movement['properties']['payment_status']['type']);
        $this->assertSame(['record_refund'], $schemas['CashRefundResponse']['properties']['data']['properties']['operation']['enum']);
    }
}
