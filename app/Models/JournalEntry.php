<?php

namespace App\Models;

use App\Enums\JournalKind;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One event in the books, and its balanced debits and credits. Written once by
 * App\Services\Finance\LedgerService; a mistake is undone by a reversing entry,
 * never by editing this one. The only columns that ever change afterwards are
 * `reversed_by_id` and `updated_at`.
 *
 * @property JournalKind $kind
 * @property \Illuminate\Support\Carbon $date
 * @property numeric-string $total
 */
class JournalEntry extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'uuid', 'entry_no', 'date', 'kind', 'memo', 'reference', 'party', 'total',
        'source_type', 'source_id', 'source_event', 'reverses_id', 'reversed_by_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'kind' => JournalKind::class,
            'total' => 'decimal:2',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    /** A person wrote it, and nobody has undone it yet. */
    public function canBeReversed(): bool
    {
        return $this->kind->isManual() && ! $this->isReversed();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
