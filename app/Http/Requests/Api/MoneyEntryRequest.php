<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\CashEntryRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Money in, money out or a transfer between books (finance.record) — the web
 * dialog's fields, with the kind sent rather than chosen by a tab.
 */
class MoneyEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.record');
    }

    public function rules(): array
    {
        $kind = (string) $this->input('kind');

        return ['kind' => ['required', 'in:in,out,transfer']] + CashEntryRequest::rulesFor($kind === 'transfer' ? 'transfer' : 'cash');
    }
}
