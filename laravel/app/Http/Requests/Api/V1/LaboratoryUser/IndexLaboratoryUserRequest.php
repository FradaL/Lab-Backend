<?php

namespace App\Http\Requests\Api\V1\LaboratoryUser;

use App\Authorization\RbacCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexLaboratoryUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim((string) $this->input('search'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'role' => ['sometimes', Rule::in(RbacCatalog::ROLES)],
            'sort' => ['sometimes', Rule::in(['name', 'email', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'laboratory_id' => ['prohibited'],
        ];
    }

    public function search(): ?string
    {
        $value = $this->validated('search');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function status(): ?string
    {
        return is_string($this->validated('status')) ? $this->validated('status') : null;
    }

    public function role(): ?string
    {
        return is_string($this->validated('role')) ? $this->validated('role') : null;
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'name');
    }

    public function direction(): string
    {
        return (string) ($this->validated('direction') ?? 'asc');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff($this->query->keys(), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Este parámetro no está permitido.');
            }
        }];
    }
}
