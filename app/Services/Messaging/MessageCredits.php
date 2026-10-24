<?php

namespace App\Services\Messaging;

use App\Models\Hospital;
use App\Models\MessageCredit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A hospital's message balance (roadmap 05 §1): a ledger that is only ever
 * posted to. A top-up or a grant adds messages, a send takes its segments.
 * Postings for one hospital are serialised on the hospital's row, so two
 * sends at the same moment can never both spend the last message.
 */
final class MessageCredits
{
    public const ENTRIES = ['topup', 'grant', 'send', 'refund'];

    /** Below this the balance is shown as running low ("Top up before reminders stop"). */
    public const LOW_BALANCE = 50;

    public function balance(int $hospitalId): int
    {
        return (int) (MessageCredit::query()->withoutGlobalScopes()
            ->where('hospital_id', $hospitalId)
            ->orderByDesc('id')
            ->value('balance_after') ?? 0);
    }

    /**
     * Post a movement. A negative one that would take the balance below zero
     * is refused: a message nobody paid for is not sent.
     *
     * @throws RuntimeException when the balance would go below zero
     */
    public function post(int $hospitalId, string $entry, int $messages, ?string $reference = null, ?int $messageId = null, ?int $by = null, ?string $note = null): MessageCredit
    {
        if (! in_array($entry, self::ENTRIES, true) || $messages === 0) {
            throw new RuntimeException('Not a ledger movement: '.$entry.' '.$messages.'.');
        }

        return DB::transaction(function () use ($hospitalId, $entry, $messages, $reference, $messageId, $by, $note) {
            Hospital::query()->whereKey($hospitalId)->lockForUpdate()->first();
            $after = $this->balance($hospitalId) + $messages;
            if ($after < 0) {
                throw new RuntimeException('Not enough message credit.');
            }

            $row = new MessageCredit([
                'entry' => $entry, 'messages' => $messages, 'balance_after' => $after,
                'reference' => $reference, 'patient_message_id' => $messageId, 'recorded_by' => $by, 'note' => $note,
            ]);
            $row->forceFill(['hospital_id' => $hospitalId])->save();

            return $row;
        });
    }
}
