<?php

namespace App\Services\Finance;

use App\Enums\JournalKind;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\FinancialYearService;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use App\Support\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of journal entries (docs/finance.md).
 *
 * Every entry is checked before anything is written: it has at least two
 * lines, every line is a debit or a credit and not both, nothing is negative,
 * every account is this hospital's and switched on, and the debits equal the
 * credits to the cent. Then the entry and its lines are written in one
 * transaction with a gap-free number.
 *
 * Nothing is ever edited. `reverse()` writes the mirror image of an entry and
 * links the two, which is how a mistake comes out of the books and stays
 * visible as having happened.
 *
 * Money is bcmath at scale 2 throughout, as everywhere else in the system.
 */
class LedgerService
{
    /**
     * @param  array{date:\DateTimeInterface|string,kind:JournalKind,memo:string,reference?:?string,party?:?string,source?:?Model,event?:?string,by?:?int,guard?:bool}  $header
     * @param  list<array{account:LedgerAccount|int,debit?:string|int|float|null,credit?:string|int|float|null,memo?:?string}>  $lines
     * @param  bool  $allowInactive  a reversal must reach an account switched off since
     */
    public function post(array $header, array $lines, bool $allowInactive = false): JournalEntry
    {
        $date = Carbon::parse($header['date'])->startOfDay();
        $source = $header['source'] ?? null;
        $event = $header['event'] ?? null;

        // A system posting has already been through the period check at the
        // counter; a backfill of last year's invoices must not be refused for
        // being last year's. Anything typed at a desk is checked here.
        if ($header['guard'] ?? true) {
            app(FinancialYearService::class)->assertPostingAllowed($date);
        }

        if ($source !== null && ($existing = $this->postedFor($source, (string) $event)) !== null) {
            return $existing;
        }

        $resolved = $this->resolve($lines, $allowInactive);

        try {
            return DB::transaction(function () use ($header, $resolved, $date, $source, $event) {
                // hospital_id set here rather than left to the model event that
                // usually fills it: seeders run with events muted, and a ledger
                // row with no hospital is a row in nobody's books.
                $entry = JournalEntry::query()->forceCreate([
                    'hospital_id' => app(CurrentHospital::class)->id(),
                    'uuid' => (string) Str::uuid(),
                    'entry_no' => $this->number($date),
                    'date' => $date->toDateString(),
                    'kind' => $header['kind'],
                    'memo' => Str::limit(trim($header['memo']), 250, ''),
                    'reference' => $this->clean($header['reference'] ?? null, 120),
                    'party' => $this->clean($header['party'] ?? null, 120),
                    'total' => $resolved['total'],
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'source_event' => $source !== null ? $event : null,
                    'created_by' => $header['by'] ?? null,
                ]);

                foreach ($resolved['lines'] as $line) {
                    $entry->lines()->forceCreate([
                        'hospital_id' => $entry->hospital_id,
                        'ledger_account_id' => $line['account']->id,
                        'date' => $date->toDateString(),
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'memo' => $this->clean($line['memo'] ?? null, 250),
                    ]);
                }

                return $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two workers posted the same source at once: the other one won,
            // and its entry is the answer.
            if ($source !== null && ($existing = $this->postedFor($source, (string) $event)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Take an entry back out of the books by writing its opposite.
     *
     * Dated today unless told otherwise, because the mistake is being put
     * right today — backdating a correction into a closed month is exactly
     * what closing a month exists to prevent.
     */
    public function reverse(JournalEntry $entry, string $reason, ?int $by = null, ?string $date = null, bool $system = false): JournalEntry
    {
        if ($entry->isReversed()) {
            throw new \DomainException('This entry has already been reversed.');
        }
        if (! $system && ! $entry->kind->isManual()) {
            throw new \DomainException('An entry the system posted is corrected by undoing what caused it, or by a journal of your own.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Say why this entry is being reversed.');
        }

        return DB::transaction(function () use ($entry, $reason, $by, $date) {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->isReversed()) {
                throw new \DomainException('This entry has already been reversed.');
            }

            $lines = $locked->lines()->get()->map(fn ($line) => [
                'account' => (int) $line->ledger_account_id,
                'debit' => (string) $line->credit,
                'credit' => (string) $line->debit,
                'memo' => $line->memo,
            ])->all();

            $reversal = $this->post([
                'date' => $date ?? Carbon::today(),
                'kind' => JournalKind::Reversal,
                'memo' => 'Reversal of '.$locked->entry_no.' — '.trim($reason),
                'reference' => $locked->reference,
                'party' => $locked->party,
                'by' => $by,
            ], $lines, true);

            $reversal->forceFill(['reverses_id' => $locked->id])->save();
            $locked->forceFill(['reversed_by_id' => $reversal->id])->save();

            return $reversal;
        });
    }

    /** The entry already posted for this source and event, if there is one. */
    public function postedFor(Model $source, string $event): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('source_event', $event)
            ->first();
    }

    /**
     * Check every line and settle on the figures. Lines with nothing on them
     * are dropped — a zero-rated invoice has no tax line, not a line of 0.00.
     *
     * @param  list<array<string,mixed>>  $lines
     * @return array{lines:list<array{account:LedgerAccount,debit:string,credit:string,memo:?string}>,total:string}
     */
    private function resolve(array $lines, bool $allowInactive = false): array
    {
        $out = [];
        $debits = '0.00';
        $credits = '0.00';

        foreach ($lines as $i => $line) {
            $debit = HospitalSettings::decimal((string) ($line['debit'] ?? '0'), 2);
            $credit = HospitalSettings::decimal((string) ($line['credit'] ?? '0'), 2);

            if (bccomp($debit, '0', 2) < 0 || bccomp($credit, '0', 2) < 0) {
                throw new \DomainException('Line '.($i + 1).': an amount cannot be negative — put it on the other side.');
            }
            if (bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) {
                throw new \DomainException('Line '.($i + 1).': a line is a debit or a credit, not both.');
            }
            if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                continue;
            }

            $account = $line['account'] instanceof LedgerAccount
                ? $line['account']
                : LedgerAccount::query()->find((int) $line['account']);

            // Tenant-scoped lookup: another hospital's account is simply not found.
            if (! $account instanceof LedgerAccount) {
                throw new \DomainException('Line '.($i + 1).': that account is not in this hospital’s chart.');
            }
            if (! $account->is_active && ! $allowInactive) {
                throw new \DomainException("“{$account->name}” is switched off — switch it back on, or post to another account.");
            }

            $debits = bcadd($debits, $debit, 2);
            $credits = bcadd($credits, $credit, 2);
            $out[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'memo' => $line['memo'] ?? null];
        }

        if (count($out) < 2) {
            throw new \DomainException('An entry needs at least one debit and one credit.');
        }
        if (bccomp($debits, $credits, 2) !== 0) {
            throw new \DomainException(
                'Debits ('.HospitalSettings::money($debits).') and credits ('.HospitalSettings::money($credits).') must be equal.'
            );
        }

        return ['lines' => $out, 'total' => $debits];
    }

    private function number(Carbon $date): string
    {
        $year = $date->format('Y');
        $seq = Sequence::next('journal', $year, fn () => JournalEntry::query()->where('entry_no', 'like', "JE-{$year}-%")->count());

        return 'JE-'.$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
