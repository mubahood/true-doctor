<?php

namespace App\Http\Requests\Api;

use App\Enums\CardStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/** A card's terms (the web card page's "Terms" dialog). */
class CardTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('patients.card.manage');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(CardStatus::class)],
            'accepts_credit' => ['boolean'],
            'max_credit' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'expiry' => ['nullable', 'date'],
            'insurance_provider_id' => ['nullable', 'integer'],
            'member_no' => ['nullable', 'string', 'max:64'],
        ];
    }
}
