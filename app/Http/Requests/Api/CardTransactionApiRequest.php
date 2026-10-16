<?php

namespace App\Http\Requests\Api;

use App\Enums\PaymentMethod;
use App\Http\Requests\CardTransactionRequest;
use Illuminate\Validation\Rule;

/** A top-up (credit, paid by cash/mobile money/bank) or a charge (debit). */
class CardTransactionApiRequest extends CardTransactionRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('patients.card.manage');
    }

    public function rules(): array
    {
        return self::rulesFor() + [
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'paid_by' => ['nullable', Rule::in(PaymentMethod::forTopUp())],
        ];
    }
}
