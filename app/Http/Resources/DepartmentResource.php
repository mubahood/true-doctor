<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Department */
class DepartmentResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'head' => $this->whenLoaded('head', fn () => $this->head ? ['id' => $this->head->id, 'name' => $this->head->name] : null),
            'head_user_id' => $this->head_user_id,
            'rooms_count' => $this->whenCounted('rooms'),
            'staff_count' => $this->whenCounted('staffProfiles'),
        ];
    }
}
