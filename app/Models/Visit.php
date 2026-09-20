<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One patient attendance — the hub everything a patient does hangs off:
 * orders, prescriptions, procedures, the bill, an admission. State only ever
 * changes through VisitService (state machine + history); vitals/BMI are
 * written by recordVitals().
 *
 * `visit_no` is annotated because it was renamed from `consultation_no` by a
 * later migration, and static analysis builds its schema by reading migrations
 * without following renames.
 *
 * Offline sync columns — see the note on `Patient` and plan §21.
 *
 * @property int $version
 * @property int|null $sync_revision
 * @property int|null $origin_device_id
 * @property string|null $origin_operation_id
 * @property \Illuminate\Support\Carbon|null $client_created_at
 * @property string $visit_no
 * @property VisitStatus $status
 * @property VisitStage $stage
 * @property VisitOutcome|null $outcome
 *                                      Hung on a row by Visits\Index only, from sums the listing query fetched —
 *                                      what the visit comes to, and what is still owed once an invoice carries it.
 *                                      `bill_balance` is null while there is no invoice, which is not the same as
 *                                      nothing owing.
 * @property numeric-string|null $bill_total
 * @property numeric-string|null $bill_balance
 * @property numeric-string $discount_value
 * @property DiscountType $discount_type
 * @property numeric-string|null $weight
 * @property numeric-string|null $height
 * @property numeric-string|null $bmi
 */
class Visit extends Model
{
    use BelongsToHospital, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'uuid', 'visit_no', 'patient_id', 'appointment_id', 'doctor_user_id',
        'receptionist_user_id', 'department_id', 'reason', 'complaints', 'diagnosis',
        'temperature', 'weight', 'height', 'bmi', 'pulse', 'respiratory_rate', 'spo2',
        'blood_pressure', 'vitals_recorded_at', 'doctor_remarks', 'receptionist_remarks',
        'patient_remarks', 'status', 'stage', 'outcome', 'completed_at',
        'discount_value', 'discount_type', 'discount_reason', 'discounted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'stage' => VisitStage::class,
            'outcome' => VisitOutcome::class,
            'discount_value' => 'decimal:2',
            'discount_type' => DiscountType::class,
            'temperature' => 'decimal:1',
            'weight' => 'decimal:2',
            'height' => 'decimal:2',
            'bmi' => 'decimal:2',
            'vitals_recorded_at' => 'datetime',
            'completed_at' => 'datetime',
            // Offline provenance. Cast because the docblock promises a Carbon,
            // and without it every ->format() on this is a fatal.
            'client_created_at' => 'datetime',
        ];
    }

    /** Every activity row is stamped with the tenant (activity_log.hospital_id). */
    public function tapActivity(\Spatie\Activitylog\Models\Activity $activity, string $eventName): void
    {
        $activity->setAttribute('hospital_id', $this->hospital_id);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['visit_no', 'status', 'stage', 'outcome', 'diagnosis'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('visit');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<VisitStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(VisitStatusHistory::class)->latest('created_at')->latest('id');
    }

    /** @return HasMany<Prescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class)->latest()->latest('id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest()->latest('id');
    }

    /**
     * Every billable line on this visit, reached through the work that raised
     * it — an item belongs to an order, and an order belongs to a visit
     * (docs/orders.md).
     *
     * @return HasManyThrough<OrderItem, Order, $this>
     */
    public function orderItems(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, Order::class)
            ->orderByDesc('order_items.id');
    }

    /** @return HasMany<Dispensation, $this> */
    public function dispensations(): HasMany
    {
        return $this->hasMany(Dispensation::class)->latest()->latest('id');
    }

    /** @return HasMany<LabOrder, $this> */
    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class)->latest()->latest('id');
    }

    /** @return HasMany<RadiologyOrder, $this> */
    public function radiologyOrders(): HasMany
    {
        return $this->hasMany(RadiologyOrder::class)->latest()->latest('id');
    }

    /** @return HasMany<TreatmentRecord, $this> */
    public function treatmentRecords(): HasMany
    {
        return $this->hasMany(TreatmentRecord::class)->latest()->latest('id');
    }

    /**
     * The bill. A visit has at most one in practice — BillingService refuses a
     * second while one is outstanding — but the relation is a HasMany because
     * nothing in the schema forbids a reissue after a cancellation.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest()->latest('id');
    }

    /** @return HasMany<Admission, $this> */
    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class)->latest()->latest('id');
    }

    /**
     * Documents handed over during this visit (a referral letter, a scan from
     * elsewhere). Patient-level artefacts like an ID scan carry no visit_id,
     * which is why this is not every document the patient has.
     *
     * @return HasMany<PatientDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class)->latest()->latest('id');
    }

    /**
     * The one word a reader wants, built from the two fields that mean it.
     *
     * Status and stage are separate because they answer different questions
     * (docs/visits.md), but nobody wants to read two badges: a visit is
     * Pending, or Ongoing, or at Billing, or Cancelled. This is that word.
     */
    public function stateLabel(): string
    {
        return match (true) {
            $this->status === VisitStatus::Pending => VisitStatus::Pending->label(),
            $this->status === VisitStatus::Completed => ($this->outcome ?? VisitOutcome::Closed)->label(),
            default => $this->stage->label(),
        };
    }

    public function stateBadge(): string
    {
        return match (true) {
            $this->status === VisitStatus::Pending => VisitStatus::Pending->badge(),
            $this->status === VisitStatus::Completed => ($this->outcome ?? VisitOutcome::Closed)->badge(),
            default => $this->stage->badge(),
        };
    }

    /**
     * Who agreed the discount. Giving money away is the kind of thing an
     * audit asks about.
     *
     * @return BelongsTo<User, $this>
     */
    public function discountedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discounted_by');
    }

    /**
     * Whether this visit's gate is open, from figures already loaded.
     *
     * The list needs this for every row, and asking the service each time
     * would be two queries apiece. Given `open_orders_count` and
     * `live_invoices_count` from withCount, it answers without touching the
     * database — the same rules VisitService::readiness applies, read off
     * numbers the one listing query already fetched.
     */
    public function gateIsOpen(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        return match ($this->stage) {
            VisitStage::Ongoing => (int) ($this->open_orders_count ?? 0) === 0,
            VisitStage::Billing => (int) ($this->live_invoices_count ?? 0) > 0,
            // Payment completes itself when the balance reaches zero, so it is
            // never something a menu offers.
            VisitStage::Payment, VisitStage::Completed => false,
        };
    }

    /** Still being dealt with — not finished, however it would end. */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Called off, rather than run to its end. */
    public function wasCancelled(): bool
    {
        return $this->outcome === VisitOutcome::Cancelled;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
