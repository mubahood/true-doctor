<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open an accounting period. The period's *shape* is validated here; the
 * no-overlap invariant belongs to FinancialYearService (the only writer).
 */
class FinancialYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller/component authorizes via FinancialYearPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('financialYear')?->id);
    }

    /**
     * The single validation source for financial years — used by this request
     * and by the Livewire modal (App\Livewire\FinancialYears\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('financial_years', 'name')->where(fn ($q) => $q->where('hospital_id', $hospitalId))->ignore($ignoreId)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }
}
