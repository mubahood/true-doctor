<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property ClaimStatus $status
 * @property numeric-string $amount
 */
class InsuranceClaim extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'claim_no', 'patient_id', 'insurance_provider_id', 'invoice_id',
        'patient_insurance_id', 'payment_id', 'amount', 'status', 'notes',
        'submitted_at', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class,
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Who settled it, where it has been settled. @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
