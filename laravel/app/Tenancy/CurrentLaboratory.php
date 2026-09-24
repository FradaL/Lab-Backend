<?php

namespace App\Tenancy;

use App\Models\Laboratory;
use LogicException;

final class CurrentLaboratory
{
    private ?Laboratory $laboratory = null;

    public function set(Laboratory $laboratory): void
    {
        $this->laboratory = $laboratory;
    }

    public function get(): Laboratory
    {
        return $this->laboratory
            ?? throw new LogicException('The current laboratory has not been resolved.');
    }

    public function id(): int
    {
        return (int) $this->get()->getKey();
    }

    public function has(): bool
    {
        return $this->laboratory !== null;
    }

    public function clear(): void
    {
        $this->laboratory = null;
    }
}
