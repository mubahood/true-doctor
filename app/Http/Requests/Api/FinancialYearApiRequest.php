<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\FinancialYearRequest;

/** Open an accounting period; FinancialYearPolicy@manage, then the web's rules. */
class FinancialYearApiRequest extends FinancialYearRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.manage');
    }
}
