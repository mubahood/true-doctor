<?php

namespace App\Services\Sync;

use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an administrator does with the machines holding an offline copy of the
 * hospital's records: list them, count them, block one, let one back in.
 * Shared by the Offline devices page and the API.
 */
class DeviceService
{
    /** A device that has not been seen for this long has probably gone. */
    public const STALE_DAYS = 14;

    /** @return array<string,int> */
    public function figures(): array
    {
        return [
            'devices' => Device::whereNull('revoked_at')->count(),
            'revoked' => Device::whereNotNull('revoked_at')->count(),
            'stale' => $this->stale(Device::query())->count(),
            'conflicts' => SyncConflict::whereNull('resolved_at')->count(),
            'rejected' => SyncOperation::where('status', 'rejected')
                ->where('created_at', '>=', now()->subDays(7))->count(),
            'failed' => SyncOperation::where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * The device list, newest activity first.
     *
     * @param  string  $state  '' every device · 'active' · 'revoked' · 'stale'
     * @return Builder<Device>
     */
    public function query(string $state = '', string $search = ''): Builder
    {
        return Device::query()
            ->with('user')
            ->when($state === 'active', fn (Builder $q) => $q->whereNull('revoked_at'))
            ->when($state === 'revoked', fn (Builder $q) => $q->whereNotNull('revoked_at'))
            ->when($state === 'stale', fn (Builder $q) => $this->stale($q))
            ->when($search !== '', fn (Builder $q) => $q
                ->where(fn (Builder $qq) => $qq
                    ->where('label', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"))))
            ->latest('last_seen_at');
    }

    /**
     * Block a device. The reason is shown to whoever next opens it.
     * The local wipe happens on the device's next contact, so this is a
     * control, not a guarantee.
     */
    public function revoke(Device $device, string $reason, User $by): Device
    {
        $device->update([
            'revoked_at' => now(),
            'revoked_by' => $by->id,
            'revoked_reason' => $reason,
        ]);

        activity()
            ->causedBy($by)
            ->performedOn($device)
            ->withProperties(['reason' => $reason, 'label' => $device->label])
            ->log('offline device revoked');

        return $device;
    }

    /** Let a device back in — a laptop found, a member of staff returned. */
    public function restore(Device $device, User $by): Device
    {
        $device->update(['revoked_at' => null, 'revoked_by' => null, 'revoked_reason' => null]);

        activity()->causedBy($by)->performedOn($device)->log('offline device restored');

        return $device;
    }

    /** @param Builder<Device> $query @return Builder<Device> */
    private function stale(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subDays(self::STALE_DAYS)));
    }
}
