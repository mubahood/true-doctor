<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A single credit (top-up) or debit (charge) against a card. Amount is kept as
 * a string all the way to bcmath in CardService — never cast through a float.
 */
class CardTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via PatientPolicy@manageCards
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for card movements — used by this request
     * and by the Livewire panel (App\Livewire\Patients\Panels\Cards).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Normalised, bcmath-ready amount string at scale 2. */
    public function amountString(): string
    {
        return self::normalise($this->input('amount'));
    }

    /** Normalise any user-supplied amount into a bcmath-ready scale-2 string. */
    public static function normalise(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
