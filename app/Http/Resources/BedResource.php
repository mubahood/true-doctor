<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Bed */
class BedResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ward_id' => $this->ward_id,
            'ward' => $this->whenLoaded('ward', fn () => $this->ward ? ['id' => $this->ward->id, 'name' => $this->ward->name] : null),
            'daily_charge' => (string) $this->daily_charge,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => (bool) $this->is_active,
            'patient' => $this->whenLoaded('currentAdmission', fn () => $this->currentAdmission?->patient ? [
                'uuid' => $this->currentAdmission->patient->uuid,
                'name' => $this->currentAdmission->patient->full_name ?? null,
            ] : null),
        ];
    }
}
