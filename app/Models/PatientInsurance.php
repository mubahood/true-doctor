<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $coverage_percent
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property bool $is_active
 */
class PatientInsurance extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'patient_id', 'insurance_provider_id', 'member_no', 'coverage_percent', 'valid_from', 'valid_to', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'coverage_percent' => 'decimal:2',
            'valid_from' => 'date', 'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<InsuranceProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(InsuranceProvider::class, 'insurance_provider_id');
    }

    public function isValid(): bool
    {
        return $this->is_active && ($this->valid_to === null || ! $this->valid_to->isPast());
    }
}
