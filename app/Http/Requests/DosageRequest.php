<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The dosage on a visit (docs/pharmacy.md, "Dosage"): one or more rows of a
 * medicine from the clinic's stock, the dose, how often, for how many days
 * and an optional note.
 */
class DosageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via PrescriptionPolicy@prescribe
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for the dosage: this request (the app),
     * the visit page's Dosage panel and the new visit form.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(string $prefix = 'items'): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            $prefix => ['required', 'array', 'min:1', 'max:30'],
            $prefix.'.*.stock_item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('hospital_id', $hospitalId)->whereNull('deleted_at')],
            $prefix.'.*.dose' => ['required', 'string', 'max:60'],
            $prefix.'.*.frequency' => ['required', 'string', 'max:60'],
            $prefix.'.*.days' => ['required', 'integer', 'min:1', 'max:90'],
            $prefix.'.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string,string> */
    public static function messagesFor(string $prefix = 'items'): array
    {
        return [
            $prefix.'.*.stock_item_id.required' => 'Choose the medicine.',
            $prefix.'.*.stock_item_id.exists' => 'Choose a medicine from your stock list.',
            $prefix.'.*.dose.required' => 'Write the dose, for example 1 tablet.',
            $prefix.'.*.frequency.required' => 'Say how often.',
            $prefix.'.*.days.required' => 'Say for how many days.',
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return self::messagesFor();
    }
}
