<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LabOrder */
class LabOrderResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'patient' => ['uuid' => $this->patient?->uuid, 'name' => $this->patient?->full_name],
            'status' => $this->status->value,
            'clinical_notes' => $this->clinical_notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'name' => $it->name,
                'unit' => $it->unit,
                'reference_range' => $it->reference_range,
                'result_value' => $it->result_value,
                'result_flag' => $it->result_flag?->value,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
