<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InsuranceClaim */
class InsuranceClaimResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'claim_no' => $this->claim_no,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'badge' => $this->status->badge(),
            'is_terminal' => $this->status->isTerminal(),
            'next_statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $this->status->transitionsTo()),
            'amount' => (string) $this->amount,
            'notes' => $this->notes,
            'patient' => $this->whenLoaded('patient', fn () => ['uuid' => $this->patient?->uuid, 'name' => $this->patient?->full_name, 'patient_no' => $this->patient?->patient_no]),
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider?->id, 'name' => $this->provider?->name]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? ['uuid' => $this->invoice->uuid, 'invoice_no' => $this->invoice->invoice_no, 'total' => (string) $this->invoice->total, 'balance' => (string) $this->invoice->balance] : null),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy?->name),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
