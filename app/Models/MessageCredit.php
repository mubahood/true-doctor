<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;

/**
 * The message balance ledger (App\Services\Messaging\MessageCredits): one row
 * per movement, never edited. A top-up adds messages, a send takes its
 * segments, and `balance_after` carries the running balance so the current
 * one is the latest row.
 *
 * @property int $id
 * @property int $hospital_id
 * @property string $entry topup · grant · send · refund
 * @property int $messages
 * @property int $balance_after
 * @property string|null $reference
 * @property int|null $patient_message_id
 * @property int|null $recorded_by
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class MessageCredit extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['entry', 'messages', 'balance_after', 'reference', 'patient_message_id', 'recorded_by', 'note'];

    protected function casts(): array
    {
        return ['messages' => 'integer', 'balance_after' => 'integer'];
    }
}
