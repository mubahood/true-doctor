<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One nursing handover: a ward, a shift, and the SBAR note of each admission
 * handed over. Append-only apart from the acknowledgement by the incoming nurse.
 *
 * @property array<int,array<string,mixed>> $notes
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 */
class HandoverNote extends Model
{
    use BelongsToHospital;

    protected $fillable = ['uuid', 'ward_id', 'shift', 'author_id', 'notes', 'acknowledged_by', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['notes' => 'array', 'acknowledged_at' => 'datetime'];
    }

    /** @return BelongsTo<Ward, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
