<?php

namespace App\Http\Requests;

use App\Models\PatientDependent;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Link an existing patient as a dependent of the guardian patient. The `exists`
 * rule is manually scoped to the current hospital: Laravel's exists rule runs a
 * raw query with no Eloquent global scope, so without this a cross-tenant uuid
 * would validate (and leak that it exists) before the scoped fetch 404'd it.
 */
class PatientDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via PatientPolicy@manageDependents
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for dependent links — used by this request
     * and by the Livewire panel (App\Livewire\Patients\Panels\Dependents).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        $exists = Rule::exists('patients', 'uuid');
        if ($hospitalId !== null) {
            $exists->where('hospital_id', $hospitalId);
        }

        return [
            'dependent_uuid' => ['required', 'uuid', $exists],
            'relationship' => ['required', Rule::in(PatientDependent::RELATIONSHIPS)],
        ];
    }
}
