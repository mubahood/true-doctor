<?php

namespace App\Models;

use App\Enums\DataExportStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hospital's full export (docs/compliance/README.md): a ZIP of one CSV per
 * table plus the uploaded files, built by App\Jobs\BuildHospitalExport and
 * downloadable through an encrypted link until it expires.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int|null $requested_by
 * @property DataExportStatus $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property int|null $tables_count
 * @property int|null $rows_count
 * @property int|null $files_count
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $download_count
 * @property \Illuminate\Support\Carbon|null $last_downloaded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class DataExport extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'uuid', 'requested_by', 'status', 'path', 'size_bytes', 'tables_count', 'rows_count', 'files_count',
        'error', 'started_at', 'finished_at', 'expires_at', 'download_count', 'last_downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DataExportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === DataExportStatus::Ready && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** Every activity row is stamped with the tenant (activity_log.hospital_id). */
    public function tapActivity(\Spatie\Activitylog\Models\Activity $activity, string $eventName): void
    {
        $activity->setAttribute('hospital_id', $this->hospital_id);
    }
}
