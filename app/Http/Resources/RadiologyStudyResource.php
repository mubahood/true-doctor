<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\RadiologyStudy */
class RadiologyStudyResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'modality' => $this->modality,
            'body_part' => $this->body_part,
            'price' => $this->price,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
