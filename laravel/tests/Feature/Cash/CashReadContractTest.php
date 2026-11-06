<?php

namespace Tests\Feature\Cash;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CashReadContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_and_routes_preserve_the_approved_read_contract(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];
        foreach (['cash-summary' => 'CashReadSummaryResponse', 'cash-movements' => 'CashReadMovementsResponse'] as $endpoint => $schema) {
            $path = '/api/v1/laboratory-orders/{order}/'.$endpoint;
            $operation = $document['paths'][$path]['get'];
            $this->assertSame([['sanctumCookie' => []]], $operation['security']);
            $this->assertSame('#/components/schemas/'.$schema, $operation['responses']['200']['content']['application/json']['schema']['$ref']);
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === ltrim($path, '/'));
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertContains('saas', $route->middleware());
            $this->assertContains('can:payments.view', $route->middleware());
        }
        $summary = $schemas['CashReadCashSummary']['oneOf'];
        $this->assertTrue($summary[0]['properties']['available']['const']);
        $this->assertFalse($summary[1]['properties']['available']['const']);
        $this->assertFalse($summary[0]['additionalProperties']);
        $this->assertFalse($summary[1]['additionalProperties']);
        foreach (['valid_payments', 'refunds', 'net_payments', 'balance_due', 'surplus', 'financial_revision'] as $field) {
            $this->assertSame('null', $summary[1]['properties'][$field]['type']);
        }
        $this->assertSame(['payment', 'payment_void', 'refund'], $schemas['CashReadCashMovement']['properties']['type']['enum']);
        $this->assertCount(3, $schemas['CashReadCashMovement']['oneOf']);
        $this->assertFalse($schemas['CashReadCashMovement']['additionalProperties']);
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total', 'financial_revision'], $schemas['CashReadPageMeta']['required']);
        $parameters = collect($document['paths']['/api/v1/laboratory-orders/{order}/cash-movements']['get']['parameters']);
        $this->assertSame(100, $parameters->firstWhere('name', 'per_page')['schema']['maximum']);
        $this->assertSame(15, $parameters->firstWhere('name', 'per_page')['schema']['default']);
        $this->assertSame(1, $parameters->firstWhere('name', 'page')['schema']['default']);
    }
}
