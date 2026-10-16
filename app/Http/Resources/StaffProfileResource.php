<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StaffProfile */
class StaffProfileResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'role' => $this->user->role, 'role_label' => $this->user->role_label] : null),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'job_title' => $this->job_title,
            'specialty' => $this->specialty,
            'license_no' => $this->license_no,
            'qualifications' => $this->qualifications,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
