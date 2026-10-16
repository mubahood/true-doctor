<?php

namespace App\Http\Requests\Api;

use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;

/** Which books the hospital's work posts to (finance.manage); the choices are FinanceSettings'. */
class FinanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.manage');
    }

    public function rules(): array
    {
        return app(FinanceSettings::class)->rules();
    }
}
