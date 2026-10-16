<?php

namespace App\Http\Resources;

use App\Models\MedicationAdministration;
use App\Models\NursingNote;
use App\Models\VitalRound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of an admission's vitals / medication / nursing-notes log. */
class NursingEntryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $base = ['id' => $r->id, 'created_at' => $r->created_at?->toIso8601String()];

        if ($r instanceof VitalRound) {
            return $base + [
                'temperature' => $r->temperature === null ? null : (string) $r->temperature,
                'pulse' => $r->pulse, 'blood_pressure' => $r->blood_pressure,
                'respiratory_rate' => $r->respiratory_rate, 'spo2' => $r->spo2,
                'note' => $r->note, 'recorded_by' => $r->recordedBy?->name,
            ];
        }

        if ($r instanceof MedicationAdministration) {
            return $base + [
                'drug_name' => $r->drug_name, 'dose' => $r->dose, 'route' => $r->route,
                'status' => $r->status->value, 'note' => $r->note, 'administered_by' => $r->administeredBy?->name,
            ];
        }

        /** @var NursingNote $r */
        return $base + ['note' => $r->note, 'recorded_by' => $r->recordedBy?->name];
    }
}
