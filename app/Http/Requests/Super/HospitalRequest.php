<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HospitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->route('hospital')?->id);
    }

    /**
     * The single validation source for platform hospitals — used by this
     * request and by the Livewire modal (App\Livewire\Super\Hospitals\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', Rule::unique('hospitals', 'slug')->ignore($ignoreId)],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['required', Rule::enum(\App\Enums\HospitalStatus::class)],
        ];
    }
}
