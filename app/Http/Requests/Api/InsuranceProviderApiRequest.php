<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\InsuranceProviderRequest;

class InsuranceProviderApiRequest extends InsuranceProviderRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('insurance.manage');
    }

    /** A form always sends its switch; an API caller that omits it means "leave it on". */
    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->has('is_active') ? $this->boolean('is_active') : true]);
    }
}
