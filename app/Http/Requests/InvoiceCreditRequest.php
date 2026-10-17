<?php

namespace App\Http\Requests;

use App\Services\InvoiceCreditService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking for a credit note or a refund (docs/refunds.md). One set of rules
 * for the invoice page and the API; the limits themselves are the service's.
 */
class InvoiceCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises through InvoicePolicy@credit
    }

    public function rules(): array
    {
        return self::rulesFor($this->kind());
    }

    public function messages(): array
    {
        return self::messagesFor();
    }

    /** @return 'credit_note'|'refund' */
    public function kind(): string
    {
        return $this->route()?->getName() === 'api.invoices.refunds' ? 'refund' : 'credit_note';
    }

    /**
     * @param  'credit_note'|'refund'  $kind
     * @param  string  $prefix  where the fields sit on a Livewire form (`credit.`)
     * @return array<string,list<mixed>>
     */
    public static function rulesFor(string $kind, string $prefix = ''): array
    {
        $rules = [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];

        if ($kind === 'refund') {
            $rules += [
                'method' => ['required', Rule::in(InvoiceCreditService::REFUND_METHODS)],
                'reference' => ['nullable', 'required_if:'.$prefix.'method,mobile_money,bank', 'string', 'max:120'],
                'card_uuid' => [
                    'nullable', 'required_if:'.$prefix.'method,card', 'uuid',
                    Rule::exists('patient_cards', 'uuid')->where('hospital_id', app(CurrentHospital::class)->id()),
                ],
            ];
        }

        $out = [];
        foreach ($rules as $field => $r) {
            $out[$prefix.$field] = $r;
        }

        return $out;
    }

    /** @return array<string,string> */
    public static function messagesFor(string $prefix = ''): array
    {
        return [
            $prefix.'reason.required' => 'Give the reason: it is printed on the document.',
            $prefix.'reference.required_if' => 'A reference is what proves this refund later.',
            $prefix.'card_uuid.required_if' => 'Choose the card to put it back on.',
            $prefix.'method.in' => 'Choose how the money goes back.',
        ];
    }
}
