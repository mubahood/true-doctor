<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CardRecord */
class CardRecordResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => (string) $this->amount,
            'signed_amount' => $this->signedAmount(),
            'balance_after' => (string) $this->balance_after,
            'description' => $this->description,
            'reference' => $this->reference,
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? ['uuid' => $this->patient->uuid, 'name' => $this->patient->full_name, 'patient_no' => $this->patient->patient_no] : null),
            'card' => $this->whenLoaded('card', fn () => $this->card ? ['uuid' => $this->card->uuid, 'masked' => $this->card->masked(), 'provider' => $this->card->relationLoaded('provider') ? $this->card->provider?->name : null] : null),
            'settlement' => $this->whenLoaded('settlement', fn () => $this->settlement ? ['reference' => $this->settlement->reference, 'created_at' => $this->settlement->created_at?->toIso8601String()] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
