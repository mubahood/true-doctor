<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientDependent extends Model
{
    use BelongsToHospital;

    public const RELATIONSHIPS = ['child', 'spouse', 'ward', 'parent', 'sibling', 'other'];

    protected $fillable = [
        'patient_id', 'dependent_patient_id', 'relationship', 'status',
    ];

    /** @return BelongsTo<Patient, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function dependent(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'dependent_patient_id');
    }
}
