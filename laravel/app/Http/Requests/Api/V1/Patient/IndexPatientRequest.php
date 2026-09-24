<?php

namespace App\Http\Requests\Api\V1\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $search = $this->input('search');

        if (is_string($search)) {
            $this->merge(['search' => trim($search)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in([
                Patient::STATUS_ACTIVE,
                Patient::STATUS_INACTIVE,
            ])],
            'sort' => ['sometimes', Rule::in([
                'first_names',
                'last_names',
                'birth_date',
                'created_at',
            ])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'laboratory_id' => ['prohibited'],
            'lab' => ['prohibited'],
        ];
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return is_string($search) && $search !== '' ? $search : null;
    }

    public function status(): ?string
    {
        $status = $this->validated('status');

        return is_string($status) ? $status : null;
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'last_names');
    }

    public function direction(): string
    {
        return (string) ($this->validated('direction') ?? 'asc');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
