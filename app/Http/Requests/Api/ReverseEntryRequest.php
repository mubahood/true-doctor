<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReverseEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.manage');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:200']];
    }
}
