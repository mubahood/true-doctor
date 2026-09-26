<?php

namespace App\Http\Resources;

use App\Support\RadiologyBench;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\RadiologyOrder */
class RadiologyOrderResource extends JsonResource
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
            'visit_no' => $this->whenLoaded('visit', fn () => $this->visit?->visit_no),
            'ordered_by' => $this->whenLoaded('orderedBy', fn () => $this->orderedBy?->name),
            'status' => $this->status->value,
            'next_statuses' => array_map(fn ($s) => $s->value, $this->status->transitionsTo()),
            'clinical_notes' => $this->clinical_notes,
            'studies' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => ['name' => $i->name, 'modality' => $i->modality])->values()),
            'findings' => $this->findings,
            'impression' => $this->impression,
            'reported_by' => $this->whenLoaded('reportedBy', fn () => $this->reportedBy?->name),
            'reported_at' => $this->reported_at?->toIso8601String(),
            'waited_hours' => RadiologyBench::waitedHours($this->resource),
            'overdue' => RadiologyBench::isOverdue($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
