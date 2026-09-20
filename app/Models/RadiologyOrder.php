<?php

namespace App\Models;

use App\Enums\RadiologyOrderStatus;
use App\Models\Concerns\BelongsToHospital;
use App\Models\Contracts\HoldsAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property RadiologyOrderStatus $status */
class RadiologyOrder extends Model implements HoldsAttachments
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'visit_id', 'patient_id', 'ordered_by', 'status', 'clinical_notes',
        'findings', 'impression', 'reported_by', 'reported_at',
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
        return ['status' => RadiologyOrderStatus::class, 'reported_at' => 'datetime'];
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

    /**
     * Who signed the report off. `reported_by` has been on the table since
     * radiology shipped with nothing able to follow it, so the report could
     * not name the radiologist who wrote it.
     *
     * @return BelongsTo<User, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return HasMany<RadiologyOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RadiologyOrderItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
