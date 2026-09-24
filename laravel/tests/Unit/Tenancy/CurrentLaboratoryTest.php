<?php

namespace Tests\Unit\Tenancy;

use App\Tenancy\CurrentLaboratory;
use LogicException;
use PHPUnit\Framework\TestCase;

class CurrentLaboratoryTest extends TestCase
{
    public function test_get_fails_explicitly_when_context_has_not_been_set(): void
    {
        $currentLaboratory = new CurrentLaboratory;

        $this->assertFalse($currentLaboratory->has());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The current laboratory has not been resolved.');

        $currentLaboratory->get();
    }

    public function test_id_fails_explicitly_when_context_has_not_been_set(): void
    {
        $currentLaboratory = new CurrentLaboratory;

        $this->assertFalse($currentLaboratory->has());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The current laboratory has not been resolved.');

        $currentLaboratory->id();
    }
}
