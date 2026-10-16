<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inpatient stay (summary). The show endpoint adds the bedside summary
 * and transfers around it (AdmissionController::show).
 *
 * @mixin \App\Models\Admission
 */
class AdmissionResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $bed = $this->bed;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->status->isActive(),
            'patient' => [
                'uuid' => $this->patient?->uuid,
                'name' => $this->patient?->full_name,
                'patient_no' => $this->patient?->patient_no,
                'alerts' => \App\Support\Clinical\PatientAlerts::forPayload($this->patient),
            ],
            'ward' => $bed?->ward === null ? null : ['id' => $bed->ward->id, 'name' => $bed->ward->name],
            'bed' => $bed === null ? null : ['id' => $bed->id, 'name' => $bed->name, 'daily_charge' => (string) $bed->daily_charge],
            'admitting_doctor' => $this->admittingDoctor === null ? null : ['id' => $this->admittingDoctor->id, 'name' => $this->admittingDoctor->name],
            'reason' => $this->reason,
            'admitted_at' => $this->admitted_at->toIso8601String(),
            'discharged_at' => $this->discharged_at?->toIso8601String(),
            'nights' => $this->nights(),
            // Nights x the rate of the bed they are in now: what discharge will bill.
            'accrued_so_far' => $this->bed_id === null ? '0.00' : bcmul((string) $bed->daily_charge, (string) $this->nights(), 2),
            'bed_charge_total' => (string) $this->bed_charge_total,
            'nights_billed' => $this->nights_billed,
            'visit' => $this->visit === null ? null : ['id' => $this->visit->id, 'uuid' => $this->visit->uuid, 'visit_no' => $this->visit->visit_no],
            'discharge_notes' => $this->discharge_notes,
        ];
    }
}
