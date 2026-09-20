<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;

class RecordSubscriptionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for a manual subscription payment — used by
     * this request and by the "Record payment" slide-over in
     * App\Livewire\Super\Subscriptions\Index.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:32'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['required', 'date'],
            'extend_days' => ['required', 'integer', 'min:0', 'max:730'],
        ];
    }
}
