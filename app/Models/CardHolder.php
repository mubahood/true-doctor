<?php

namespace App\Models;

use App\Enums\CardHolderRelationship;
use App\Enums\CardHolderStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody other than the primary holder who may spend on a card.
 *
 * Revoked, never deleted (docs/cards.md): the charges they made stay on the
 * ledger, and the row is what explains them.
 *
 * @property CardHolderRelationship $relationship
 * @property CardHolderStatus $status
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class CardHolder extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'patient_card_id', 'patient_id', 'relationship', 'status',
        'added_by', 'revoked_by', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'relationship' => CardHolderRelationship::class,
            'status' => CardHolderStatus::class,
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PatientCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(PatientCard::class, 'patient_card_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
