<?php

namespace App\Http\Requests\Api\V1\LaboratoryUser;

use App\Authorization\RbacCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InviteLaboratoryUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('name'))) {
            $normalized['name'] = trim((string) $this->input('name'));
        }
        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim((string) $this->input('email')));
        }
        if (is_string($this->input('role'))) {
            $normalized['role'] = trim((string) $this->input('role'));
        }
        $this->merge($normalized);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(RbacCatalog::ROLES)],
            'laboratory_id' => ['prohibited'],
            'password' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido.');
            }
        }];
    }
}
