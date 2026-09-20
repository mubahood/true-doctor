<?php

namespace App\Models;

use App\Enums\AdmissionStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property AdmissionStatus $status
 * @property numeric-string $bed_charge_total the nights billed so far, summed
 * @property int $nights_billed how many nights are already on the bill
 * @property \Illuminate\Support\Carbon $admitted_at
 * @property \Illuminate\Support\Carbon|null $discharged_at
 */
class Admission extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_id', 'bed_id', 'visit_id', 'admitting_doctor_id',
        'status', 'reason', 'admitted_at', 'discharged_at', 'discharge_notes', 'bed_charge_total', 'nights_billed',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdmissionStatus::class,
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'bed_charge_total' => 'decimal:2',
            'nights_billed' => 'integer',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Bed, $this> */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function admittingDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitting_doctor_id');
    }

    /** @return HasMany<BedTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(BedTransfer::class)->latest('created_at')->latest('id');
    }

    /** @return HasMany<NursingNote, $this> */
    public function nursingNotes(): HasMany
    {
        return $this->hasMany(NursingNote::class)->latest('created_at')->latest('id');
    }

    /** @return HasMany<VitalRound, $this> */
    public function vitalRounds(): HasMany
    {
        return $this->hasMany(VitalRound::class)->latest('created_at')->latest('id');
    }

    /** @return HasMany<MedicationAdministration, $this> */
    public function medications(): HasMany
    {
        return $this->hasMany(MedicationAdministration::class)->latest('created_at')->latest('id');
    }

    /** Nights stayed so far (min 1), used for the bed charge. */
    public function nights(): int
    {
        $end = $this->discharged_at ?? now();

        return max(1, (int) $this->admitted_at->diffInDays($end));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
