<?php

namespace App\Models;

use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One spreadsheet brought in through Settings, Import data (docs/imports.md):
 * who uploaded it and when, how its columns were read, and what became of its
 * rows. Only App\Services\DataImportService changes it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property ImportKind $kind
 * @property ImportStatus $status
 * @property string $file_name
 * @property list<string>|null $headings
 * @property array<string,int|null>|null $column_map field key => column index
 * @property int $total_rows
 * @property int $added_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property int $undone_count
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $imported_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $undone_by
 * @property \Illuminate\Support\Carbon|null $undone_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class ImportBatch extends Model
{
    use BelongsToHospital;

    /** How long after an import it can still be undone. */
    public const UNDO_HOURS = 24;

    protected $fillable = [
        'uuid', 'kind', 'status', 'file_name', 'headings', 'column_map', 'total_rows',
        'added_count', 'updated_count', 'skipped_count', 'failed_count', 'undone_count',
        'created_by', 'imported_at', 'finished_at', 'undone_by', 'undone_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ImportKind::class,
            'status' => ImportStatus::class,
            'headings' => 'array',
            'column_map' => 'array',
            'imported_at' => 'datetime',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    /** The last moment the whole batch can be undone, once it has been imported. */
    public function undoUntil(): ?\Illuminate\Support\Carbon
    {
        return $this->finished_at?->copy()->addHours(self::UNDO_HOURS);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
