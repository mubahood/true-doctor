<?php

namespace App\Models;

use App\Enums\DentalTreatmentStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A procedure planned for a patient, from the clinic's own price list,
 * optionally on a tooth and surfaces. Done means billed: `order_item_id`
 * is the line that charged it (DentalService::transition).
 *
 * @property int $id
 * @property string $uuid
 * @property int $patient_id
 * @property int|null $visit_id
 * @property int $service_id
 * @property int|null $tooth
 * @property string|null $surfaces
 * @property numeric-string $price
 * @property DentalTreatmentStatus $status
 * @property string|null $notes
 * @property int|null $planned_by
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $done_at
 * @property int|null $done_by
 * @property int|null $done_visit_id
 * @property int|null $order_item_id
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class DentalTreatmentItem extends Model
{
    use BelongsToHospital;

    protected $fillable = ['uuid', 'patient_id', 'visit_id', 'service_id', 'tooth', 'surfaces', 'price', 'status', 'notes', 'planned_by'];

    protected function casts(): array
    {
        return [
            'status' => DentalTreatmentStatus::class,
            'price' => 'decimal:2',
            'tooth' => 'integer',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'done_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<User, $this> */
    public function plannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planned_by');
    }

    /** @return BelongsTo<User, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
