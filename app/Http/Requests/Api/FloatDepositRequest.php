<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** An insurer's deposit into its float, or (with `direction`) a correction of it. */
class FloatDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('insurance.manage');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
