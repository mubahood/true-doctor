<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The limit inputs are named exactly like the keys App\Support\PlanLimit reads
 * (max_staff / max_patients / max_beds) — the mismatch that made every
 * UI-edited plan unlimited (K4) is not reintroducible without renaming both.
 */
class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->route('plan')?->id);
    }

    /**
     * The single validation source for plans — used by this request and by the
     * Livewire modal (App\Livewire\Super\Plans\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:150', Rule::unique('plans', 'slug')->ignore($ignoreId)],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', Rule::enum(\App\Enums\BillingCycle::class)],
            'max_staff' => ['nullable', 'integer', 'min:1'],
            'max_patients' => ['nullable', 'integer', 'min:1'],
            'max_beds' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            // Nullable, not required: clicking "Add a feature" and leaving the row
            // blank is not an error — the blank row is dropped on save.
            'features.*' => ['nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
