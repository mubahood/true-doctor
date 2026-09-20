<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Appointment */
class AppointmentResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'patient' => [
                'uuid' => $this->patient?->uuid,
                'name' => $this->patient?->full_name,
                'patient_no' => $this->patient?->patient_no,
            ],
            'doctor' => $this->doctor?->name,
            'doctor_user_id' => $this->doctor_user_id,
            'department_id' => $this->department_id,
            'room_id' => $this->room_id,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'reason' => $this->reason,
            'next_statuses' => array_map(fn ($s) => $s->value, $this->status->transitionsTo()),
        ];
    }
}
