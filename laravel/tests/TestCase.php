<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithPermissionTeams;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithPermissionTeams;

    public function createApplication()
    {
        $app = parent::createApplication();
        TestDatabaseGuard::verify($app);

        return $app;
    }
}
