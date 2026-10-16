<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;

/**
 * "This record is gone from your list": a delete, or an order closed out of a
 * worklist. Written by {@see \App\Observers\TombstoneObserver}, served in
 * `sync/pull` and `GET /bootstrap` as `removed`, pruned after 90 days.
 *
 * @property int $id
 * @property int $hospital_id
 * @property string $entity
 * @property string $uuid
 * @property int $revision
 * @property string $reason
 * @property \Illuminate\Support\Carbon $deleted_at
 */
class SyncTombstone extends Model
{
    use BelongsToHospital;

    /** How long a tombstone is kept. A device away longer rebuilds from scratch. */
    public const RETAIN_DAYS = 90;

    public $timestamps = false;

    protected $fillable = ['hospital_id', 'entity', 'uuid', 'revision', 'reason', 'deleted_at'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'deleted_at' => 'datetime'];
    }

    /**
     * Delete tombstones past retention, at most once every 12 hours.
     *
     * Registered to run EVERY minute and decide for itself, because a
     * standalone copy's cron may fire only every 19 minutes: a `dailyAt('03:50')`
     * entry would almost never land on its minute. The cache lock makes the
     * cheap check the common case; losing the cache only means one extra prune.
     *
     * @return int|null rows deleted, or null when it was not due
     */
    public static function pruneIfDue(): ?int
    {
        if (! \Illuminate\Support\Facades\Cache::add('sync:tombstones-pruned', now()->toIso8601String(), now()->addHours(12))) {
            return null;
        }

        return self::withoutGlobalScopes()->where('deleted_at', '<', now()->subDays(self::RETAIN_DAYS))->delete();
    }

    /** The shape every API response uses. @return array{entity:string, uuid:string, at:string, reason:string, revision:int} */
    public function toRemoval(): array
    {
        return [
            'entity' => $this->entity,
            'uuid' => $this->uuid,
            'at' => $this->deleted_at->toIso8601String(),
            'reason' => $this->reason,
            'revision' => $this->revision,
        ];
    }

    /** The permission a user needs to be told about a removal of this entity. */
    public static function permissionFor(string $entity): ?string
    {
        return match ($entity) {
            'patients' => 'patients.view',
            'visits', 'services' => 'visits.view',
            'invoices' => 'billing.view',
            'stock_items' => 'pharmacy.view',
            'appointments' => 'appointments.view',
            'lab_orders', 'lab_items' => 'lab.view',
            'radiology_orders' => 'radiology.view',
            default => null,
        };
    }
}
