<?php

namespace App\Models;

use App\Enums\PatientSex;
use App\Enums\PatientStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A patient of one hospital. Tenant-scoped by BelongsToHospital; identity is
 * `patient_no` (generated + checksummed by PatientService, unique per hospital).
 * Never carries login credentials — patients are not users (B6).
 *
 * @property int $id
 * @property int $hospital_id
 * @property string $patient_no
 * @property string $first_name
 * @property string $last_name
 * @property \Illuminate\Support\Carbon|null $dob
 * @property PatientSex|null $sex
 * @property PatientStatus $status
 * @property array<int,string>|null $allergies
 * @property array<int,string>|null $chronic_conditions
 * @property bool $consent_given
 * @property \Illuminate\Support\Carbon|null $consent_at
 * @property \Illuminate\Support\Carbon $created_at
 *                                                  Offline sync adds three columns to this table (docs/OFFLINE_FIRST_IMPLEMENTATION_PLAN.md §21):
 *                                                  `version` for optimistic concurrency — a device edits against a version and
 *                                                  the server refuses a write made against a stale one; `sync_revision` for the
 *                                                  pull cursor; and `origin_*` so a record captured on a tablet can always
 *                                                  answer which device and which operation produced it.
 * @property int $version
 * @property int|null $sync_revision
 * @property int|null $origin_device_id
 * @property string|null $origin_operation_id
 * @property \Illuminate\Support\Carbon|null $client_created_at
 * @property-read string $full_name
 */
class Patient extends Model
{
    use BelongsToHospital, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_no', 'first_name', 'last_name', 'dob', 'sex',
        'phone_1', 'phone_2', 'email', 'address', 'home_address', 'district_id',
        'blood_type', 'allergies', 'chronic_conditions',
        'spouse_name', 'father_name', 'mother_name',
        'emergency_contact_name', 'emergency_contact_phone',
        'insurance_provider', 'insurance_member_no', 'bank_details',
        'consent_given', 'consent_at', 'photo', 'notes', 'status', 'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'sex' => PatientSex::class,
            'status' => PatientStatus::class,
            'allergies' => 'array',
            'chronic_conditions' => 'array',
            'bank_details' => 'encrypted',   // C12 — sensitive at rest
            'consent_given' => 'boolean',
            'consent_at' => 'datetime',
            // Offline provenance. Cast because the docblock promises a Carbon,
            // and without it every ->format() on this is a fatal.
            'client_created_at' => 'datetime',
        ];
    }

    protected $hidden = ['bank_details'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['patient_no', 'first_name', 'last_name', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('patient');
    }

    // ── Relationships ──────────────────────────────────────────

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** @return HasMany<PatientCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(PatientCard::class)->latest();
    }

    /** @return HasMany<PatientDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class)->latest();
    }

    /** @return HasMany<PatientInsurance, $this> */
    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class)->latest();
    }

    /** @return HasMany<TreatmentRecord, $this> */
    public function treatmentRecords(): HasMany
    {
        return $this->hasMany(TreatmentRecord::class)->latest('performed_at');
    }

    /**
     * Links where this patient is the guardian.
     *
     * @return HasMany<PatientDependent, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(PatientDependent::class, 'patient_id');
    }

    /**
     * Links where this patient is the dependent.
     *
     * @return HasMany<PatientDependent, $this>
     */
    public function guardianLinks(): HasMany
    {
        return $this->hasMany(PatientDependent::class, 'dependent_patient_id');
    }

    // ── Accessors ──────────────────────────────────────────────

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getFullNameAttribute(): string
    {
        return $this->fullName();
    }

    public function age(): ?int
    {
        return $this->dob?->age;
    }

    public function initials(): string
    {
        return strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    // ── Scopes ─────────────────────────────────────────────────

    /** Free-text search across name, patient_no and phone (tenant-scoped by the global scope). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('patient_no', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('phone_1', 'like', "%{$term}%")
                ->orWhere('phone_2', 'like', "%{$term}%");
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
