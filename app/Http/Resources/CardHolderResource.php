<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CardHolder */
class CardHolderResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient' => ['uuid' => $this->patient?->uuid, 'name' => $this->patient?->full_name, 'patient_no' => $this->patient?->patient_no],
            'relationship' => $this->relationship->value,
            'relationship_label' => $this->relationship->label(),
            'status' => $this->status->value,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'added_by' => $this->whenLoaded('addedBy', fn () => $this->addedBy?->name),
        ];
    }
}
