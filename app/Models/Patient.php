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
 * @property \Illuminate\Support\Carbon|null $next_recall_on
 * @property list<string>|null $medical_flags red flags from the medical history (App\Support\Clinical\MedicalFlags)
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
    // Changes are audited field by field by AuditObserver (docs/audit.md).
    use BelongsToHospital, HasFactory, SoftDeletes;

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
            'next_recall_on' => 'date',
            // Red flags (HIV or hepatitis, pregnancy among them): encrypted like `notes`.
            'medical_flags' => 'encrypted:array',
            'bank_details' => 'encrypted',   // C12 — sensitive at rest
            // Free text where a standing fact like HIV status gets written;
            // never searched, so it can be encrypted (roadmap 07 §3).
            'notes' => 'encrypted',
            'consent_given' => 'boolean',
            'consent_at' => 'datetime',
            // Offline provenance. Cast because the docblock promises a Carbon,
            // and without it every ->format() on this is a fatal.
            'client_created_at' => 'datetime',
        ];
    }

    protected $hidden = ['bank_details'];

    // ── Relationships ──────────────────────────────────────────

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<User, $this> */
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

    /**
     * Free-text search across name, patient_no and phone (tenant-scoped by the
     * global scope). Every word must match somewhere, so "Amina Nakato" finds
     * Amina Nakato — each word alone matched only half of a full name.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return self::matchWords($query, $term);
    }

    /**
     * The search itself, for a query over patients reached through a relation
     * (a visit's, a lab order's, an invoice's patient).
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function matchWords(Builder $query, ?string $term): Builder
    {
        $words = preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            // A phone number matches however it was written (+256 7.., 07..):
            // by the last nine digits every form of it shares.
            $tail = preg_match('/^\+?[\d\-]{9,}$/', $word) === 1 ? \App\Support\PhoneNumber::tail($word) : null;
            $query->where(function (Builder $q) use ($word, $tail) {
                if ($tail !== null) {
                    $q->orWhere('phone_1', 'like', "%{$tail}")->orWhere('phone_2', 'like', "%{$tail}");
                }
                $q->orWhere('patient_no', 'like', "%{$word}%")
                    ->orWhere('first_name', 'like', "%{$word}%")
                    ->orWhere('last_name', 'like', "%{$word}%")
                    ->orWhere('phone_1', 'like', "%{$word}%")
                    ->orWhere('phone_2', 'like', "%{$word}%");
            });
        }

        return $query;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
