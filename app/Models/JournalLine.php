<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit or one credit of a journal entry — never both on one line. The
 * entry's date is copied here so an account's balance is one indexed sum.
 *
 * @property \Illuminate\Support\Carbon $date
 * @property numeric-string $debit
 * @property numeric-string $credit
 */
class JournalLine extends Model
{
    use BelongsToHospital;

    public $timestamps = false;

    protected $fillable = ['journal_entry_id', 'ledger_account_id', 'date', 'debit', 'credit', 'memo'];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
