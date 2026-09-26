<?php

namespace App\Http\Resources;

use App\Support\LabBench;
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
            'server_id' => $this->id,
            'patient' => [
                'uuid' => $this->patient?->uuid,
                'name' => $this->patient?->full_name,
                'patient_no' => $this->patient?->patient_no,
            ],
            'visit_no' => $this->whenLoaded('visit', fn () => $this->visit?->visit_no),
            'ordered_by' => $this->whenLoaded('orderedBy', fn () => $this->orderedBy?->name),
            'status' => $this->status->value,
            // Where the bench may move it next — LabOrderStatus's own rules.
            'next_statuses' => array_map(fn ($s) => $s->value, $this->status->transitionsTo()),
            'clinical_notes' => $this->clinical_notes,
            'waited_hours' => LabBench::waitedHours($this->resource),
            'overdue' => LabBench::isOverdue($this->resource),
            // Every line a device may report against, with the version its
            // result would be written over (the sync stream's lab_items).
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'uuid' => $it->uuid,
                'server_id' => $it->id,
                'version' => (int) ($it->version ?? 1),
                'name' => $it->name,
                'unit' => $it->unit,
                'reference_range' => $it->reference_range,
                'result_value' => $it->result_value,
                'result_flag' => $it->result_flag?->value,
                'result_notes' => $it->result_notes,
                'resulted_at' => $it->resulted_at?->toIso8601String(),
            ])->values()),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
