<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * One entry in the hospital's write audit (roadmap 07 §3, docs/audit.md):
 * who changed which record, when, and each field's value before and after,
 * with secrets and encrypted fields masked.
 *
 * The activitylog package writes through this model (config/activitylog.php),
 * so every entry, the package's own `activity()` calls included, carries the
 * tenant and is read only by its own hospital. Append-only: Eloquent refuses
 * to edit or delete one. (Purging a whole hospital removes its rows with the
 * query builder, on purpose, in `hospitals:purge`.)
 *
 * @property int|null $hospital_id
 */
class AuditEntry extends Activity
{
    use BelongsToHospital;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit entries are append-only.');
        });
        static::deleting(function (): void {
            throw new LogicException('Audit entries are append-only.');
        });
    }

    /** @return array<string,mixed> */
    public function newValues(): array
    {
        return (array) ($this->properties?->get('attributes') ?? []);
    }

    /** @return array<string,mixed> */
    public function oldValues(): array
    {
        return (array) ($this->properties?->get('old') ?? []);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
