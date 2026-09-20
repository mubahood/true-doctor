<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InsuranceClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for a claim — used by this request and by
     * the Livewire modal (App\Livewire\InsuranceClaims\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('hospital_id', $hospitalId)],
            'insurance_provider_id' => ['required', 'integer', Rule::exists('insurance_providers', 'id')->where('hospital_id', $hospitalId)],
            // Required: a claim is raised against a bill, and every bill belongs to a
            // visit — that is how a claim reaches the visit it is claiming for.
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('hospital_id', $hospitalId)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
