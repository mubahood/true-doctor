<?php

namespace App\Models;

use App\Enums\LabOrderStatus;
use App\Models\Concerns\BelongsToHospital;
use App\Models\Contracts\HoldsAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property LabOrderStatus $status */
class LabOrder extends Model implements HoldsAttachments
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'visit_id', 'patient_id', 'ordered_by', 'status', 'clinical_notes', 'completed_at',
    ];

    /**
     * The files that go with the result — the report the machine printed, the
     * film, the scanned request. Shared with the visit module's orders, one
     * store and one policy (docs/lab-radiology.md).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\App\Models\OrderAttachment, $this>
     */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\OrderAttachment::class, 'attachable')->latest()->latest('id');
    }

    protected function casts(): array
    {
        return ['status' => LabOrderStatus::class, 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /** @return HasMany<LabOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
