<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithPermissionTeams;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithPermissionTeams;
}
