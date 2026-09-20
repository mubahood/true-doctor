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
        ];
    }
}
