<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'hospital_id' => $this->hospital_id,
            'hospital' => $this->whenLoaded('hospital', fn () => ['id' => $this->hospital?->id, 'name' => $this->hospital?->name]),
            'is_super_admin' => $this->isSuperAdmin(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
