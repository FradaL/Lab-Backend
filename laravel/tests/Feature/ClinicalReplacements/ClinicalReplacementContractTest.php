<?php

namespace Tests\Feature\ClinicalReplacements;

use App\Authorization\RbacCatalog;
use App\ClinicalReplacements\ReplacementAuthority;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClinicalReplacementContractTest extends TestCase
{
    public function test_openapi_generates_with_resolvable_references_and_all_six_routes(): void
    {
        $path = storage_path('framework/testing/clinical-replacements-openapi');
        File::ensureDirectoryExists($path);
        config(['l5-swagger.defaults.paths.docs' => $path]);
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $document = json_decode(file_get_contents($path.'/api-docs.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertReferences($document, $document);
        $prefix = '/api/v1/laboratory-orders/{order}/exams/{line}/clinical-replacements';
        $schemas = ['/intentions' => 'ReplacementIntentionInput', '/evidence' => 'ClinicalLineEvidenceInput', '/assessments' => 'ClinicalViabilityInput', '/intentions/{intention}/samples' => 'ReplacementLinkInput', '/intentions/{intention}/fulfill' => 'ReplacementFulfillmentInput'];
        $this->assertArrayHasKey('get', $document['paths'][$prefix]);
        foreach ($schemas as $suffix => $schema) {
            $operation = $document['paths'][$prefix.$suffix]['post'];
            $this->assertSame('#/components/schemas/'.$schema, $operation['requestBody']['content']['application/json']['schema']['$ref']);
            $this->assertArrayHasKey('409', $operation['responses']);
        }
        $required = $document['components']['schemas']['ClinicalViabilityInput']['required'];
        $this->assertContains('expected_dependency_token', $required);
        $this->assertContains('previous_id', $required);
        $this->assertCount(6, $document['components']['schemas']['ClinicalLineEvidenceInput']['oneOf']);
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'clinical-replacements'));
        $this->assertCount(6, $routes);
        foreach ($routes as $route) {
            $this->assertContains('saas', $route->gatherMiddleware());
            $this->assertNotEmpty(array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, 'can:orders.clinical_replacements.')));
        }
        foreach (RbacCatalog::rolePermissions() as $name => $permissions) {
            $this->assertEqualsCanonicalizing($name === 'owner' ? ReplacementAuthority::PERMISSIONS : [], array_values(array_intersect(ReplacementAuthority::PERMISSIONS, $permissions)));
        }
    }

    private function assertReferences(array $node, array $root): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
                $target = $root;
                foreach (explode('/', substr($value, 2)) as $segment) {
                    $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                    $this->assertArrayHasKey($segment, $target, $value);
                    $target = $target[$segment];
                }
            } elseif (is_array($value)) {
                $this->assertReferences($value, $root);
            }
        }
    }
}
