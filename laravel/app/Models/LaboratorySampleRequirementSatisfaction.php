<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read-only live projection; persisted BE-S2-04 status is not clinical readiness. */
class LaboratorySampleRequirementSatisfaction extends Model
{
    protected $table = 'laboratory_sample_requirement_satisfactions';

    protected $primaryKey = 'sample_requirement_id';

    public $timestamps = false;

    public function save(array $options = []): bool
    {
        throw new \LogicException('Sample satisfaction is derived and cannot be written.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Sample satisfaction is derived and cannot be deleted.');
    }
}
