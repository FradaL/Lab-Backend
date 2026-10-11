<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['laboratory_id', 'laboratory_exam_id', 'number', 'revision', 'status', 'base_version_id', 'configuration', 'configuration_hash', 'published_at', 'published_by'])]
class LaboratoryExamVersion extends Model
{
    use BelongsToLaboratory;

    protected function casts(): array
    {
        return ['configuration' => 'array', 'published_at' => 'immutable_datetime', 'number' => 'integer', 'revision' => 'integer'];
    }
}
