<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property array<int,string> $slots
 * @property \Illuminate\Support\Carbon $start_date
 * @property int $days
 * @property string|null $uuid
 * @property string|null $frequency
 */
class DoseItem extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'uuid', 'prescription_id', 'stock_item_id', 'drug_name', 'dosage', 'frequency', 'slots', 'days', 'start_date', 'instructions',
    ];

    /**
     * How often, as the dosage section offers it (docs/pharmacy.md, "Dosage").
     * Stored as the words themselves, so a free-text answer ("every 6 hours")
     * sits in the same column and prints the same way.
     */
    public const FREQUENCIES = [
        'Once a day',
        'Twice a day',
        'Three times a day',
        'Four times a day',
        'When needed',
    ];

    protected static function booted(): void
    {
        static::creating(function (DoseItem $item) {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'slots' => 'array',
            'days' => 'integer',
            'start_date' => 'date',
        ];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** How often, in words: the dosage's own, else the daily slots of an older line. */
    public function howOften(): string
    {
        if (filled($this->frequency)) {
            return (string) $this->frequency;
        }

        $slots = array_values((array) $this->slots);

        return $slots === [] ? '' : implode(', ', array_map('ucfirst', $slots));
    }

    /** @return HasMany<DoseItemRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(DoseItemRecord::class)->orderBy('scheduled_date')->orderBy('slot');
    }
}
