<?php

namespace App\Http\Requests;

use App\Enums\BedStatus;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class BedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via BedPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('bed')?->id);
    }

    /**
     * The single validation source for beds — used by this request and by the
     * Livewire modal (App\Livewire\Beds\Index). Bed names are unique per ward
     * only by convention, so $ignoreId is accepted for signature symmetry.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'ward_id' => ['required', 'integer', Rule::exists('wards', 'id')->where('hospital_id', $hospitalId)],
            'name' => ['required', 'string', 'max:48'],
            'daily_charge' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'status' => ['required', new Enum(BedStatus::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
