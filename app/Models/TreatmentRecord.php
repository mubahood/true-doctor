<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property array<string,mixed>|null $meta */
class TreatmentRecord extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_id', 'visit_id', 'performed_by', 'procedure', 'description', 'meta', 'performed_at',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'performed_at' => 'datetime'];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return HasMany<TreatmentRecordItem, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(TreatmentRecordItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
