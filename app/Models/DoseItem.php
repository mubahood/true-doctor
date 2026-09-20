<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int,string> $slots
 * @property \Illuminate\Support\Carbon $start_date
 * @property int $days
 */
class DoseItem extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'prescription_id', 'drug_name', 'dosage', 'slots', 'days', 'start_date', 'instructions',
    ];

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

    /** @return HasMany<DoseItemRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(DoseItemRecord::class)->orderBy('scheduled_date')->orderBy('slot');
    }
}
