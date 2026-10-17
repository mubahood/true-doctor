<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an imported sheet: the cells as they were, what the check found,
 * what the person chose, and the record it became (docs/imports.md).
 *
 * @property int $id
 * @property int $hospital_id
 * @property int $import_batch_id
 * @property int $line the row number in the sheet, as the person sees it in Excel
 * @property list<string> $cells
 * @property string $status one of the STATUS_* constants
 * @property list<string>|null $problems plain-language reasons the row cannot be saved
 * @property int|null $match_id the registered record it looks like
 * @property string|null $match_reason
 * @property string|null $decision skip | add | update, for a likely duplicate
 * @property string|null $record_type
 * @property int|null $record_id
 * @property array<string,mixed>|null $extra what else the row created (an opening invoice, a stock movement)
 * @property string|null $message
 */
class ImportRow extends Model
{
    use BelongsToHospital;

    // Before importing: what the check found.
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const DUPLICATE = 'duplicate';

    public const PROBLEM = 'problem';

    // After importing: what happened.
    public const ADDED = 'added';

    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const UNDONE = 'undone';

    public const KEPT = 'kept';

    public const DECISIONS = ['skip' => 'Skip', 'add' => 'Add anyway', 'update' => 'Update the registered one'];

    public const LABELS = [
        self::PENDING => 'Not checked yet',
        self::READY => 'Will be added',
        self::DUPLICATE => 'Looks registered',
        self::PROBLEM => 'Has a problem',
        self::ADDED => 'Added',
        self::UPDATED => 'Updated',
        self::SKIPPED => 'Skipped',
        self::FAILED => 'Not saved',
        self::UNDONE => 'Undone',
        self::KEPT => 'Kept (used since)',
    ];

    public const TONES = [
        self::PENDING => 'neutral', self::READY => 'info', self::DUPLICATE => 'warn', self::PROBLEM => 'danger',
        self::ADDED => 'success', self::UPDATED => 'success', self::SKIPPED => 'neutral', self::FAILED => 'danger',
        self::UNDONE => 'neutral', self::KEPT => 'warn',
    ];

    protected $fillable = [
        'import_batch_id', 'line', 'cells', 'status', 'problems', 'match_id', 'match_reason',
        'decision', 'record_type', 'record_id', 'extra', 'message',
    ];

    protected function casts(): array
    {
        return [
            'cells' => 'array',
            'problems' => 'array',
            'extra' => 'array',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function tone(): string
    {
        return self::TONES[$this->status] ?? 'neutral';
    }
}
