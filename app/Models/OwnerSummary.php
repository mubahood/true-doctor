<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner's daily summary for one day (App\Services\OwnerSummaryService).
 * Unique per owner and day: the scheduler runs every half hour, the owner
 * hears about a day once.
 *
 * @property int $id
 * @property int $hospital_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $summary_date
 * @property string $channel
 * @property string|null $recipient
 * @property string $body
 * @property string $status
 * @property string|null $error
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OwnerSummary extends Model
{
    use BelongsToHospital;

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** A failed send is tried again on the next runs, this many attempts in all. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = ['user_id', 'summary_date', 'channel', 'recipient', 'body', 'status', 'error', 'attempts', 'sent_at'];

    protected function casts(): array
    {
        return ['summary_date' => 'date', 'sent_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
