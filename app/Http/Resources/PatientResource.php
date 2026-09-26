<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Patient */
class PatientResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'patient_no' => $this->patient_no,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'sex' => $this->sex?->value,
            'dob' => $this->dob?->toDateString(),
            'age' => $this->age(),
            'phone_1' => $this->phone_1,
            'email' => $this->email,
            'blood_type' => $this->blood_type,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),

            // The rest of the editable record, and its version, so a client
            // that found a patient by searching can hold it and edit it through
            // offline sync with the base a three-way merge needs — the same
            // fields the sync pull sends (PullService). Never bank_details.
            'server_id' => $this->id,
            'version' => (int) ($this->version ?? 1),
            'phone_2' => $this->phone_2,
            'address' => $this->address,
            'home_address' => $this->home_address,
            'district_id' => $this->district_id,
            'allergies' => $this->allergies,
            'chronic_conditions' => $this->chronic_conditions,
            'spouse_name' => $this->spouse_name,
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'notes' => $this->notes,
            'insurance_provider' => $this->insurance_provider,
            'insurance_member_no' => $this->insurance_member_no,
            'consent_given' => (bool) $this->consent_given,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
