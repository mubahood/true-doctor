<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Generate an invoice from a visit, with an optional flat discount. */
class InvoiceGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via InvoicePolicy@create
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for invoice generation — used by this request
     * and by App\Livewire\Visits\Panels\Charges.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    /** A blank/absent discount is "0.00"; anything else is normalised to 2dp. */
    public static function normalise(mixed $discount): string
    {
        return number_format((float) ($discount ?: 0), 2, '.', '');
    }

    public function discountString(): string
    {
        return self::normalise($this->input('discount'));
    }
}
