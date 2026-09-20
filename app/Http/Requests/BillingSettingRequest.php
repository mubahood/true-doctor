<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The hospital owner/admin's billing configuration — currency, tax and default
 * fees. Persisted to the hospital's `currency` column + `settings.billing` JSON.
 */
class BillingSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via manage-settings
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * One source of truth for the billing rules — shared by this FormRequest and
     * the Livewire editor (Settings\Billing), house rule 13.
     *
     * @return array<string,list<string>>
     */
    public static function rulesFor(): array
    {
        return [
            'currency_code' => ['required', 'string', 'min:2', 'max:5', 'alpha'],
            'currency_symbol' => ['nullable', 'string', 'max:8'],
            'currency_position' => ['required', 'in:before,after'],
            'decimals' => ['required', 'integer', 'in:0,2,3'],
            'thousands_separator' => ['nullable', 'string', 'max:1'],
            'decimal_separator' => ['required', 'string', 'max:1'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_label' => ['nullable', 'string', 'max:20'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'consultation_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'invoice_prefix' => ['required', 'string', 'max:10', 'alpha_dash'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tax_enabled' => $this->boolean('tax_enabled'),
            'currency_code' => strtoupper((string) $this->input('currency_code')),
        ]);
    }
}
