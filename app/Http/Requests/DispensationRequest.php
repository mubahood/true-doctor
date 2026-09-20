<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Dispense one or more stock items against a visit. */
class DispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via pharmacy.dispense
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for a dispensation — used by this request and
     * by the repeater in App\Livewire\Visits\Panels\Dispense (which drops
     * the `prescription_id` key its form does not expose).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'note' => ['nullable', 'string', 'max:255'],
            'prescription_id' => ['nullable', 'integer', Rule::exists('prescriptions', 'id')->where('hospital_id', $hospitalId)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('hospital_id', $hospitalId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }
}
