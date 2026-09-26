<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\MetaController;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Visit */
class VisitResource extends JsonResource
{
    /**
     * VisitService::readiness(), when the caller asked for it — a single
     * visit's page shows its gate; a list works it out from counts instead.
     *
     * @var array<string,mixed>|null
     */
    private ?array $gate = null;

    /** @param  array{stage:\App\Enums\VisitStage,next:?\App\Enums\VisitStage,ready:bool,blocker:?string,label:?string,automatic:bool}  $readiness */
    public function withGate(array $readiness): static
    {
        $this->gate = [
            'next' => $readiness['next']?->value,
            'ready' => $readiness['ready'] && $this->resource->isOpen(),
            'blocker' => $readiness['blocker'],
            'label' => $readiness['label'],
            'automatic' => $readiness['automatic'],
        ];

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $patient = $this->patient;

        return [
            'uuid' => $this->uuid,
            'server_id' => $this->id,
            'version' => (int) ($this->version ?? 1),
            'visit_no' => $this->visit_no,
            'patient' => [
                'uuid' => $patient?->uuid,
                'name' => $patient?->full_name,
                'patient_no' => $patient?->patient_no,
                'sex' => $patient?->sex?->value,
                'age' => $patient?->age(),
                'phone' => $patient?->phone_1,
                'blood_type' => $patient?->blood_type,
                // Shown on the visit, where somebody is about to prescribe.
                'allergies' => array_values(array_filter((array) ($patient->allergies ?? []))),
            ],
            'doctor' => $this->doctor?->name,
            'doctor_user_id' => $this->doctor_user_id,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'complaints' => $this->complaints,
            'diagnosis' => $this->diagnosis,
            'doctor_remarks' => $this->doctor_remarks,
            'receptionist_remarks' => $this->receptionist_remarks,
            'patient_remarks' => $this->patient_remarks,
            'vitals' => [
                'temperature' => $this->temperature,
                'blood_pressure' => $this->blood_pressure,
                'weight' => $this->weight,
                'height' => $this->height,
                'bmi' => $this->bmi,
                'pulse' => $this->pulse,
                'respiratory_rate' => $this->respiratory_rate,
                'spo2' => $this->spo2,
                'recorded_at' => $this->vitals_recorded_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Two fields now, and one next stage rather than a set of legal
            // targets: whether it can be reached is the visit's own business
            // (docs/visits.md), so the API reports the gate, not a menu.
            'stage' => $this->stage->value,
            'outcome' => $this->outcome?->value,
            'state' => $this->resource->stateLabel(),
            'state_tone' => MetaController::tone($this->resource->stateBadge()),
            'is_open' => $this->resource->isOpen(),
            'next_stage' => $this->stage->next()?->value,
            // A list carries the counts the gate needs (VisitController@index),
            // the same shortcut the web list uses; one visit carries the gate.
            'advance_ready' => $this->when(
                $this->gate !== null || array_key_exists('open_orders_count', $this->resource->getAttributes()),
                fn () => $this->gate['ready'] ?? $this->resource->gateIsOpen(),
            ),
            'gate' => $this->when($this->gate !== null, fn () => $this->gate),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'at' => $h->created_at?->toIso8601String(),
                'from' => $h->fromLabel(),
                'to' => $h->toLabel(),
                'note' => $h->note,
                'by' => $h->changedBy?->name,
                'override' => (bool) $h->is_override,
            ])->values()),
        ];
    }
}
