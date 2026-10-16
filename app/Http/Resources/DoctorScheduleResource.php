<?php

namespace App\Http\Resources;

use App\Services\DoctorScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One availability window. @mixin \App\Models\DoctorSchedule */
class DoctorScheduleResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $span = max(0, DoctorScheduleService::minutesOf($this->end_time) - DoctorScheduleService::minutesOf($this->start_time));

        return [
            'id' => $this->id,
            'doctor_id' => $this->user_id,
            'doctor' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),
            'room_id' => $this->room_id,
            'room' => $this->whenLoaded('room', fn () => $this->room?->name),
            'weekday' => $this->weekday->value,
            'weekday_label' => $this->weekday->label(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'slot_minutes' => $this->slot_minutes,
            'minutes' => $span,
            'slots' => $this->slot_minutes > 0 ? intdiv($span, $this->slot_minutes) : 0,
            'is_active' => $this->is_active,
        ];
    }
}
