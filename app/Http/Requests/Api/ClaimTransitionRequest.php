<?php

namespace App\Http\Requests\Api;

use App\Enums\ClaimStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ClaimTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('insurance.manage');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(ClaimStatus::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
