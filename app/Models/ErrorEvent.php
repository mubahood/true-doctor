<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A kind of error the system hit (ErrorTracker). Platform data: the hospital
 * is recorded for context and never used to scope.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $source
 * @property string $class
 * @property string $message
 * @property string|null $file
 * @property int|null $line
 * @property string|null $path
 * @property string|null $last_ref
 * @property list<string>|null $refs
 * @property int|null $last_user_id
 * @property int|null $last_hospital_id
 * @property string|null $app_version
 * @property string|null $release
 * @property int $occurrences
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class ErrorEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'refs' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'occurrences' => 'integer',
            'line' => 'integer',
        ];
    }
}
