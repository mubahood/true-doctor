<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InsuranceTransaction */
class InsuranceTransactionResource extends JsonResource
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
            'reference' => $this->reference,
            'notes' => $this->notes,
            'card' => $this->whenLoaded('card', fn () => $this->card ? ['uuid' => $this->card->uuid, 'masked' => $this->card->masked(), 'patient' => $this->card->patient?->full_name] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
