<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account in the chart (docs/finance.md). An account marked `is_book` is
 * somewhere money actually sits — a cash drawer, a bank account, a mobile-money
 * wallet — and is what a receipt or a payment names.
 *
 * `system_key` is how the hospital's own work finds the account it posts to
 * ("receivable", "revenue.lab"). An account with one can be renamed and
 * renumbered but not removed or switched off: the system would have nowhere to
 * post.
 *
 * @property AccountType $type
 * @property bool $is_book
 * @property bool $is_active
 */
class LedgerAccount extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'code', 'name', 'type', 'subtype', 'system_key', 'is_book', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_book' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @param Builder<LedgerAccount> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param Builder<LedgerAccount> $query */
    public function scopeBooks(Builder $query): Builder
    {
        return $query->where('is_book', true);
    }

    /** @param Builder<LedgerAccount> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('code')->orderBy('id');
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /** "1000 · Cash at hand" — how an account is named in a picker. */
    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
