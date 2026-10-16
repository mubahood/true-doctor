<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff account as the Users page shows it. Never a password, a remember
 * token or anything that would let the account be signed into.
 *
 * @mixin \App\Models\User
 */
class StaffUserResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'role_label' => $this->role_label,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'is_active' => (bool) $this->is_active,
            'sees_patients' => (bool) $this->sees_patients,
            'password_change_required' => (bool) $this->password_change_required,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'profile' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile ? (new StaffProfileResource($this->staffProfile))->resolve() : null),
        ];
    }
}
