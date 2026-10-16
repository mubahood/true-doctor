<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\InsuranceClaimRequest;
use App\Support\CurrentHospital;
use Illuminate\Validation\Rule;

/**
 * Raise a claim (insurance.manage). The web picks the patient and invoice by
 * id; the app only knows uuids, so those two are sent as `patient_uuid` and
 * `invoice_uuid` — the amount, insurer and notes rules are the web's own.
 */
class ClaimApiRequest extends InsuranceClaimRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('insurance.manage');
    }

    public function rules(): array
    {
        $hospital = app(CurrentHospital::class)->id();

        return [
            'patient_uuid' => ['required', 'uuid', Rule::exists('patients', 'uuid')->where('hospital_id', $hospital)],
            'invoice_uuid' => ['required', 'uuid', Rule::exists('invoices', 'uuid')->where('hospital_id', $hospital)],
        ] + array_diff_key(self::rulesFor(), ['patient_id' => 1, 'invoice_id' => 1]);
    }
}
