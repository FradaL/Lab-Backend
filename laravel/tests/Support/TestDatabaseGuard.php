<?php

namespace Tests\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Application;
use RuntimeException;

final class TestDatabaseGuard
{
    public static function assertConfiguration(string $environment, string $connection, array $config): void
    {
        $expected = [
            'driver' => 'pgsql',
            'host' => 'postgres-testing',
            'port' => '5432',
            'database' => 'donqer_lab_testing',
            'username' => 'donqer_lab_testing',
            'password' => 'testing-only-isolated-postgres',
        ];

        if ($environment !== 'testing' || $connection !== 'pgsql') {
            throw new RuntimeException('Testing database safety: environment/connection rejected.');
        }

        foreach ($expected as $key => $value) {
            if (! is_scalar($config[$key] ?? null) || (string) $config[$key] !== $value) {
                throw new RuntimeException("Testing database safety: {$key} rejected.");
            }
        }

        if (! empty($config['url']) || isset($config['read']) || isset($config['write'])) {
            throw new RuntimeException('Testing database safety: URL/replica overrides rejected.');
        }
    }

    public static function protect(Application $app): void
    {
        self::verify($app);
        $app['events']->listen(CommandStarting::class, function (CommandStarting $event) use ($app): void {
            if (str_starts_with($event->command ?? '', 'migrate') || $event->command === 'db:wipe') {
                $selected = $event->input->hasParameterOption('--database')
                    ? $event->input->getParameterOption('--database')
                    : $app['config']->get('database.default');
                if ($selected !== 'pgsql') {
                    throw new RuntimeException('Testing database safety: command connection rejected.');
                }
                self::verify($app);
            }
        });
    }

    public static function verify(Application $app): void
    {
        if ($app['config']->get('app.env') !== 'testing') {
            throw new RuntimeException('Testing database safety: configured environment rejected.');
        }
        $name = $app['config']->get('database.default');
        self::assertConfiguration($app->environment(), $name, $app['config']->get("database.connections.{$name}", []));
        $connection = $app['db']->connection($name);
        // Validate the parsed effective connection too; never connect before the whitelist passes.
        self::assertConfiguration($app->environment(), $name, $connection->getConfig());
        $identity = $connection->selectOne("select current_database() as database, current_user as username, current_setting('server_version_num')::integer as version");
        if ($identity->database !== 'donqer_lab_testing' || $identity->username !== 'donqer_lab_testing'
            || $identity->version < 170000 || $identity->version >= 180000) {
            throw new RuntimeException('Testing database safety: server identity rejected.');
        }
    }
}
