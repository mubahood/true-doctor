<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Ward */
class WardResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'default_daily_charge' => (string) $this->default_daily_charge,
            'is_active' => (bool) $this->is_active,
            'beds_count' => $this->whenCounted('beds'),
            'beds' => $this->whenLoaded('beds', fn () => BedResource::collection($this->beds)->resolve()),
        ];
    }
}
