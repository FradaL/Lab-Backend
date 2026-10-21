<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;

final class TestingDatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TestDatabaseGuard::protect($this->app);
        // Laravel normally disables console lifecycle events whenever APP_ENV=testing.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    }
}
