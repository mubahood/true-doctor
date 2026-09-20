<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentRecordItem extends Model
{
    use BelongsToHospital;

    protected $fillable = ['treatment_record_id', 'photo_path', 'caption'];

    /** @return BelongsTo<TreatmentRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(TreatmentRecord::class, 'treatment_record_id');
    }
}
