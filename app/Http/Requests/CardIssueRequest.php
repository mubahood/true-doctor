<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue a new prepaid card for a patient. The card number itself is never
 * user-supplied — CardService allocates and hashes it (C12).
 */
class CardIssueRequest extends FormRequest
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
     * The single validation source for card issuance — used by this request
     * and by the Livewire panel (App\Livewire\Patients\Panels\Cards).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'expiry' => ['nullable', 'date', 'after:today'],
            'accepts_credit' => ['sometimes', 'boolean'],
            'max_credit' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            // An insurance card. CardService checks the insurer is this
            // hospital's — the id alone proves nothing (docs/cards.md).
            'insurance_provider_id' => ['nullable', 'integer'],
            'member_no' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'accepts_credit' => $this->boolean('accepts_credit'),
            'max_credit' => $this->boolean('accepts_credit') ? ($this->input('max_credit') ?: 0) : 0,
        ]);
    }
}
