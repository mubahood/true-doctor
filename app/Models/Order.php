<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToHospital;
use App\Models\Contracts\HoldsAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing to be done for a patient on a visit — see docs/orders.md.
 *
 * The order is the request layer: what it is, who should do it, where it has
 * got to, and what it cost. The cost is its items; the clinical detail, where
 * there is any, is its `subject` (a LabOrder, an Admission, and so on).
 *
 * @property OrderType $type
 * @property OrderStatus $status
 * @property \Illuminate\Support\Carbon|null $report_updated_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class Order extends Model implements HoldsAttachments
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    /**
     * Stamped on the orders the charges-became-orders migration invented for
     * bills that predate orders. Their titles were generated, not chosen, so
     * anything learning from what staff actually write must skip them.
     */
    public const CARRIED_OVER = 'Carried over when charges became orders';

    protected $fillable = [
        'uuid', 'visit_id', 'patient_id', 'type', 'status', 'title', 'notes',
        'report', 'report_updated_at',
        'assigned_to', 'department_id', 'requested_by',
        'subject_type', 'subject_id',
        'started_at', 'completed_at', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'report_updated_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->latest()->latest('id');
    }

    /**
     * What was found, and the files that go with it.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<OrderAttachment, $this>
     */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(OrderAttachment::class, 'attachable')->latest()->latest('id');
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** The specialist record this order produced, where its type has one. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether anything was actually recorded against this order.
     *
     * Three things count, and any one of them is enough: a line that still
     * stands on the bill, a written report, or a file attached to it — a lab
     * result that arrived as a PDF is evidence of work whether or not anybody
     * typed a summary of it.
     *
     * This is what "completed" has to mean. An order finished with nothing on
     * it tells the next reader that something was done and leaves no trace of
     * what, which is worse than one still sitting open.
     */
    public function hasEvidence(): bool
    {
        return $this->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->exists()
            || trim((string) $this->report) !== ''
            || $this->attachments()->exists();
    }

    /** Still to be done: the worklist. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [OrderStatus::Pending->value, OrderStatus::InProgress->value]);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
