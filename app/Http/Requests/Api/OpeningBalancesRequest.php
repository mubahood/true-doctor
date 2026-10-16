<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** The balances a hospital started with (finance.manage): account id => amount. */
class OpeningBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.manage');
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'balances' => ['required', 'array'],
            'balances.*' => ['nullable', 'numeric', 'min:-9999999999.99', 'max:9999999999.99'],
        ];
    }
}
