<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

final class TestDatabaseGuardTest extends TestCase
{
    private const CONFIG = [
        'driver' => 'pgsql', 'host' => 'postgres-testing', 'port' => 5432,
        'database' => 'donqer_lab_testing', 'username' => 'donqer_lab_testing',
        'password' => 'testing-only-isolated-postgres', 'url' => null,
    ];

    public function test_only_the_explicit_testing_configuration_is_authorized(): void
    {
        TestDatabaseGuard::assertConfiguration('testing', 'pgsql', self::CONFIG);
        $this->expectNotToPerformAssertions();
    }

    public function test_cli_environment_cannot_authorize_a_cached_development_configuration(): void
    {
        $app = new Application;
        $app->instance('env', 'testing');
        $app->instance('config', new Repository(['app' => ['env' => 'local']]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('configured environment rejected');
        TestDatabaseGuard::verify($app);
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_destinations_are_rejected_before_any_connection(string $environment, string $connection, array $override): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::assertConfiguration($environment, $connection, array_replace(self::CONFIG, $override));
    }

    public static function unsafeConfigurations(): iterable
    {
        yield 'development environment' => ['local', 'pgsql', []];
        yield 'unexpected connection' => ['testing', 'sqlite', []];
        foreach ([
            'driver' => 'sqlite', 'host' => 'postgres', 'database' => 'donqer_lab',
            'username' => 'default', 'password' => 'secret', 'port' => 5433,
            'url' => 'pgsql://other-host/other-db', 'read' => ['host' => 'postgres'],
            'write' => ['host' => 'postgres'],
        ] as $key => $value) {
            yield $key => ['testing', 'pgsql', [$key => $value]];
        }
        yield 'multiple hosts' => ['testing', 'pgsql', ['host' => ['postgres-testing', 'postgres']]];
        yield 'missing host' => ['testing', 'pgsql', ['host' => null]];
    }
}
