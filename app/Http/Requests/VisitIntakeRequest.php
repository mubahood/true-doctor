<?php

namespace App\Http\Requests;

use App\Enums\PatientSex;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Register a brand-new patient AND open their visit in one step — the
 * legacy's bug-ridden "inline patient intake during visit" flow, rebuilt
 * as an explicit DTO (never transient attributes on a model — §2.3, constraint 3).
 */
class VisitIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via VisitPolicy@create + PatientPolicy@create
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for inline intake — used by this request and
     * by the Livewire intake tab (App\Livewire\Visits\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            // New patient
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'sex' => ['nullable', new Enum(PatientSex::class)],
            'dob' => ['nullable', 'date', 'before:today'],
            'phone_1' => ['nullable', 'string', 'max:30'],
            'consent_given' => ['sometimes', 'boolean'],
            // Visit
            'doctor_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('hospital_id', $hospitalId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('hospital_id', $hospitalId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'complaints' => ['nullable', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            // Is anybody seeing them yet? A visit opened at the desk is
            // Pending; one opened by the person about to see them is Ongoing,
            // and VisitService::start() writes that move into the trail.
            'start_now' => ['sometimes', 'boolean'],
            // Vitals come from the ONE place that validates vitals, so the
            // ranges a nurse is held to at the desk are the ranges they are
            // held to in the panel.
        ] + VisitVitalsRequest::rulesFor();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['consent_given' => $this->boolean('consent_given')]);
    }

    /** @return array<string,mixed> patient attributes only */
    public function patientData(): array
    {
        return $this->safe()->only(['first_name', 'last_name', 'sex', 'dob', 'phone_1', 'consent_given']);
    }

    /** @return array<string,mixed> visit attributes only */
    public function visitData(): array
    {
        return $this->safe()->only(['doctor_user_id', 'department_id', 'reason', 'complaints']);
    }
}
