<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Record a payment. A card payment requires a prepaid card of this hospital. */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via InvoicePolicy@pay
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * One source of truth for the payment rules (plan §4.5): the API request and
     * App\Livewire\Invoices\Show both validate with these.
     *
     * @return array<string,list<mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        $needsReference = array_map(fn (PaymentMethod $m) => $m->value, array_filter(PaymentMethod::cases(), fn (PaymentMethod $m) => $m->needsReference()));

        return [
            // Recorded by hand: a gateway payment arrives from the gateway.
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::recordable()))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reference' => ['nullable', 'required_if:method,'.implode(',', $needsReference), 'string', 'max:120'],
            'card_uuid' => [
                'nullable', 'required_if:method,card', 'uuid',
                Rule::exists('patient_cards', 'uuid')->where('hospital_id', $hospitalId),
            ],
        ];
    }

    public function messages(): array
    {
        return self::messagesFor();
    }

    /** @return array<string,string> */
    public static function messagesFor(): array
    {
        return [
            'reference.required_if' => 'A reference is what proves this payment later.',
            'card_uuid.required_if' => 'Choose the card to debit.',
            'method.in' => 'Choose how it was paid.',
        ];
    }

    public function amountString(): string
    {
        return number_format((float) $this->input('amount'), 2, '.', '');
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('method'));
    }
}
