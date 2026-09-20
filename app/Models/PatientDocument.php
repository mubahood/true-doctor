<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientDocument extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    public const TYPES = ['id', 'insurance', 'consent', 'referral', 'result', 'other'];

    protected $fillable = [
        'uuid', 'patient_id', 'type', 'file_path', 'original_name',
        'mime', 'size', 'note', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
