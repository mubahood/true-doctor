<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Visit */
class VisitResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'visit_no' => $this->visit_no,
            'patient' => [
                'uuid' => $this->patient?->uuid,
                'name' => $this->patient?->full_name,
                'patient_no' => $this->patient?->patient_no,
            ],
            'doctor' => $this->doctor?->name,
            'doctor_user_id' => $this->doctor_user_id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'complaints' => $this->complaints,
            'diagnosis' => $this->diagnosis,
            'vitals' => [
                'temperature' => $this->temperature,
                'blood_pressure' => $this->blood_pressure,
                'weight' => $this->weight,
                'height' => $this->height,
                'bmi' => $this->bmi,
                'pulse' => $this->pulse,
                'spo2' => $this->spo2,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            // Two fields now, and one next stage rather than a set of legal
            // targets: whether it can be reached is the visit's own business
            // (docs/visits.md), so the API reports the gate, not a menu.
            'stage' => $this->stage->value,
            'outcome' => $this->outcome?->value,
            'state' => $this->resource->stateLabel(),
            'next_stage' => $this->stage->next()?->value,
        ];
    }
}
