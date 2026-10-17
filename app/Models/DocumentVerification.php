<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;

/**
 * What the public verification page may say about one printed document
 * (App\Services\DocumentVerifier): what it is, its number, the day it was
 * printed and the patient's initials. Never its contents.
 *
 * @property int $id
 * @property int $hospital_id
 * @property string $code
 * @property string $fingerprint
 * @property string $title
 * @property string|null $reference
 * @property string|null $patient_initials
 * @property \Illuminate\Support\Carbon $issued_at
 * @property int $checks_count
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class DocumentVerification extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['code', 'fingerprint', 'title', 'reference', 'patient_initials', 'issued_at', 'checks_count', 'last_checked_at'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'checks_count' => 'integer',
        ];
    }

    /** The code as printed: two groups of four. */
    public static function display(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
