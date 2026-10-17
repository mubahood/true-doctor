<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the diagnosis catalogue (ICD-10 now, ICD-11 ready): shared by
 * every hospital, read-only to them, shipped with the app's reference data.
 *
 * @property int $id
 * @property string $system icd10 · icd11
 * @property string $code
 * @property string $title
 * @property list<string>|null $synonyms
 * @property string $search lower-cased code, title and synonyms, for the picker
 * @property bool $is_active
 */
class DiagnosisCode extends Model
{
    protected $fillable = ['system', 'code', 'title', 'synonyms', 'search', 'is_active'];

    protected function casts(): array
    {
        return ['synonyms' => 'array', 'is_active' => 'boolean'];
    }
}
