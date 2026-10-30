<?php

namespace App\Http\Requests\Api\V1;

use App\Authorization\RbacCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReplaceRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.permissions.manage');
    }

    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'permissions' => ['present', 'array', 'list', 'max:'.count(RbacCatalog::PERMISSIONS)],
            'permissions.*' => ['required', 'string', 'distinct:strict', Rule::in(RbacCatalog::PERMISSIONS)],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido.');
            }
        }];
    }
}
