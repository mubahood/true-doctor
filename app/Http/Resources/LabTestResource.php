<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LabTest */
class LabTestResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'specimen' => $this->specimen,
            'unit' => $this->unit,
            'reference_range' => $this->reference_range,
            'price' => $this->price,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
