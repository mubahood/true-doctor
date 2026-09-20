<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a department. Name and code are unique *per hospital*; the
 * unique rules are scoped to the current hospital by hand because a bare
 * unique rule would collide across tenants (and leak names between them).
 */
class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via DepartmentPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('department')?->id);
    }

    /**
     * The single validation source for departments — used by this request
     * (API/controller) and by the Livewire modal (App\Livewire\Departments\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        $scoped = fn (string $column) => Rule::unique('departments', $column)
            ->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))
            ->ignore($ignoreId);

        return [
            'name' => ['required', 'string', 'max:120', $scoped('name')],
            'code' => ['nullable', 'string', 'max:24', $scoped('code')],
            'head_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('hospital_id', $hospitalId)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'code' => $this->filled('code') ? strtoupper(trim($this->input('code'))) : null,
        ]);
    }
}
