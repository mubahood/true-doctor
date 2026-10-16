<?php

namespace App\Http\Resources;

use App\Services\Sync\DeviceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Device */
class DeviceResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $stale = $this->revoked_at === null
            && ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subDays(DeviceService::STALE_DAYS)));

        return [
            'uuid' => $this->device_uuid,
            'label' => $this->label,
            'platform' => $this->platform,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'state' => $this->revoked_at !== null ? 'revoked' : ($stale ? 'stale' : 'active'),
            'registered_at' => $this->registered_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,
            'revoked_by' => $this->whenLoaded('revokedBy', fn () => $this->revokedBy ? ['id' => $this->revokedBy->id, 'name' => $this->revokedBy->name] : null),
        ];
    }
}
