<?php

namespace App\Livewire\Concerns;

use App\Http\Requests\PatientRequest;
use App\Models\Patient;
use App\Services\PatientService;

/**
 * Shared patient create/edit form state + persistence. Used by both the
 * full-page editor (Patients\Form) and the index modal (Patients\Index) so
 * there is one field set, one validation source (the same PatientRequest rules
 * the API uses), and one persistence path (PatientService). Because the
 * properties live on the component itself, views bind `wire:model="first_name"`
 * and show `@error('first_name')` with no prefix.
 */
trait InteractsWithPatientForm
{
    // Identity
    public string $first_name = '';

    public string $last_name = '';

    public ?string $dob = null;

    public ?string $sex = null;

    public ?string $blood_type = null;

    public string $status = 'active';

    // Contact
    public ?string $phone_1 = null;

    public ?string $phone_2 = null;

    public ?string $email = null;

    public ?string $district_id = null;

    public ?string $address = null;

    public ?string $home_address = null;

    // Medical (comma-separated strings in the UI, arrays in the model)
    public string $allergies = '';

    public string $chronic_conditions = '';

    // Family & emergency
    public ?string $spouse_name = null;

    public ?string $father_name = null;

    public ?string $mother_name = null;

    public ?string $emergency_contact_name = null;

    public ?string $emergency_contact_phone = null;

    // Insurance & consent
    public ?string $insurance_provider = null;

    public ?string $insurance_member_no = null;

    public ?string $notes = null;

    public bool $consent_given = false;

    protected function fillPatientForm(Patient $p): void
    {
        $this->first_name = (string) $p->first_name;
        $this->last_name = (string) $p->last_name;
        $this->dob = $p->dob?->toDateString();
        $this->sex = $p->sex?->value;
        $this->blood_type = $p->blood_type;
        $this->status = $p->status->value;
        $this->phone_1 = $p->phone_1;
        $this->phone_2 = $p->phone_2;
        $this->email = $p->email;
        $this->district_id = $p->district_id !== null ? (string) $p->district_id : null;
        $this->address = $p->address;
        $this->home_address = $p->home_address;
        $this->allergies = is_array($p->allergies) ? implode(', ', $p->allergies) : '';
        $this->chronic_conditions = is_array($p->chronic_conditions) ? implode(', ', $p->chronic_conditions) : '';
        $this->spouse_name = $p->spouse_name;
        $this->father_name = $p->father_name;
        $this->mother_name = $p->mother_name;
        $this->emergency_contact_name = $p->emergency_contact_name;
        $this->emergency_contact_phone = $p->emergency_contact_phone;
        $this->insurance_provider = $p->insurance_provider;
        $this->insurance_member_no = $p->insurance_member_no;
        $this->notes = $p->notes;
        $this->consent_given = (bool) $p->consent_given;
    }

    protected function resetPatientForm(): void
    {
        $this->reset([
            'first_name', 'last_name', 'dob', 'sex', 'blood_type',
            'phone_1', 'phone_2', 'email', 'district_id', 'address', 'home_address',
            'allergies', 'chronic_conditions', 'spouse_name', 'father_name', 'mother_name',
            'emergency_contact_name', 'emergency_contact_phone',
            'insurance_provider', 'insurance_member_no', 'notes', 'consent_given',
        ]);
        $this->status = 'active';
        $this->resetErrorBag();
    }

    /** @return array<string, mixed> */
    protected function patientPayload(): array
    {
        $data = [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'dob' => $this->dob,
            'sex' => $this->sex,
            'blood_type' => $this->blood_type,
            'status' => $this->status,
            'phone_1' => $this->phone_1,
            'phone_2' => $this->phone_2,
            'email' => $this->email,
            'district_id' => $this->district_id,
            'address' => $this->address,
            'home_address' => $this->home_address,
            'spouse_name' => $this->spouse_name,
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'insurance_provider' => $this->insurance_provider,
            'insurance_member_no' => $this->insurance_member_no,
            'notes' => $this->notes,
        ];

        foreach ($data as $k => $v) {
            if ($v === '') {
                $data[$k] = null;
            }
        }

        $data['allergies'] = $this->splitPatientList($this->allergies);
        $data['chronic_conditions'] = $this->splitPatientList($this->chronic_conditions);
        $data['consent_given'] = $this->consent_given;

        return $data;
    }

    /** @return list<string> */
    private function splitPatientList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }

    /**
     * Validate against the shared PatientRequest rules, then create or update
     * through PatientService. $patient is null for a fresh registration.
     */
    protected function persistPatient(PatientService $service, ?Patient $patient): Patient
    {
        $data = $this->patientPayload();

        validator($data, (new PatientRequest)->rules())->validate();

        return $patient
            ? $service->update($patient, $data)
            : $service->register($data, auth()->id());
    }
}
