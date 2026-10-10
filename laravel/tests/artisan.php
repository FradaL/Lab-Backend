<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\Support\TestDatabaseGuard;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
try {
    $app->make(Kernel::class)->bootstrap();
    TestDatabaseGuard::verify($app);
    exit($app->handleCommand(new ArgvInput));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
