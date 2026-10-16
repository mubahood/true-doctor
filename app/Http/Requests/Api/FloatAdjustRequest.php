<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class FloatAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('insurance.manage');
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', 'in:add,remove'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
