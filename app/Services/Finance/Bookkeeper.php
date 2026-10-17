<?php

namespace App\Services\Finance;

use App\Enums\CardEntryType;
use App\Enums\InsuranceEntryType;
use App\Enums\InvoiceStatus;
use App\Enums\JournalKind;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementReason;
use App\Models\CardRecord;
use App\Models\InsuranceTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Model;

/**
 * The hospital's own work, written into its books (docs/finance.md).
 *
 * Each method takes one record the system has just written and posts the
 * double entry it means, in the same transaction, under the record's own
 * hospital. Each is idempotent — keyed on the record and the event — so a
 * retry, or a backfill run over records already posted, finds the entry that
 * is there instead of writing a second.
 *
 *   An invoice is issued    Dr receivables, discounts      Cr income by kind of work, tax
 *   … and later voided      the mirror image of the above
 *   A payment is taken      Dr the book it landed in       Cr receivables
 *     … by prepaid card     Dr card deposits               Cr receivables
 *     … by insurance        Dr insurance receivables       Cr receivables
 *   A card is topped up     Dr the top-up book             Cr card deposits
 *   An insurer deposits     Dr the insurer-deposit book    Cr insurer deposits
 *   A float settles a card  Dr insurer deposits            Cr card deposits
 *   Stock is received       Dr inventory                   Cr payables (or the book named)
 *   Stock is dispensed      Dr cost of sales               Cr inventory
 *   Stock is lost           Dr stock losses                Cr inventory
 *
 * System postings may land on an account a hospital has since switched off:
 * a setting in the finance pages must never be what stops a patient being
 * billed. Income for a service follows its own account where it has one, and
 * its kind of order where it does not.
 */
class Bookkeeper
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerService $ledger,
    ) {}

    // ── Billing ──────────────────────────────────────────────────────────

    public function invoiceIssued(Invoice $invoice): ?JournalEntry
    {
        // A draft was never issued, and a void one never earned anything —
        // unless it was posted before it was voided, which invoiceVoided() undoes.
        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true)) {
            return null;
        }

        return $this->as($invoice, function () use ($invoice) {
            $lines = [['account' => $this->chart->key('receivable'), 'debit' => (string) $invoice->total, 'memo' => $invoice->invoice_no]];

            if (bccomp((string) $invoice->discount, '0', 2) > 0) {
                $lines[] = ['account' => $this->chart->key('discounts'), 'debit' => (string) $invoice->discount, 'memo' => 'Discount'];
            }

            // Income by the account each line earns into, then anything the
            // lines do not account for (an invoice written before its lines
            // were snapshotted, a rounding cent) as other patient income — so
            // the entry balances to the invoice, which is the document of record.
            $byAccount = [];
            $earned = '0.00';
            $items = $invoice->items()->get();
            $sources = $this->orderItemsFor($items);

            foreach ($items as $item) {
                $account = $this->incomeAccountFor($item, $sources[$item->source_id] ?? null);
                $byAccount[$account->id] ??= ['account' => $account, 'credit' => '0.00'];
                $byAccount[$account->id]['credit'] = bcadd($byAccount[$account->id]['credit'], (string) $item->line_total, 2);
                $earned = bcadd($earned, (string) $item->line_total, 2);
            }

            $gap = bcsub((string) $invoice->subtotal, $earned, 2);
            if (bccomp($gap, '0', 2) !== 0) {
                $other = $this->chart->key('revenue.other');
                $byAccount[$other->id] ??= ['account' => $other, 'credit' => '0.00'];
                $byAccount[$other->id]['credit'] = bcadd($byAccount[$other->id]['credit'], $gap, 2);
            }

            foreach ($byAccount as $row) {
                // A negative here would be a line that took money off the bill;
                // it belongs on the debit side.
                $lines[] = bccomp($row['credit'], '0', 2) >= 0
                    ? ['account' => $row['account'], 'credit' => $row['credit']]
                    : ['account' => $row['account'], 'debit' => bcmul($row['credit'], '-1', 2)];
            }

            if (bccomp((string) $invoice->tax_total, '0', 2) > 0) {
                $lines[] = ['account' => $this->chart->key('tax.output'), 'credit' => (string) $invoice->tax_total, 'memo' => 'Tax charged'];
            }

            return $this->post($invoice, 'issued', $invoice->issued_at ?? $invoice->created_at,
                'Invoice '.$invoice->invoice_no.$this->patientSuffix($invoice), $lines, $invoice->invoice_no, $invoice->issued_by);
        });
    }

    /**
     * An invoice cancelled after it was booked: its entry comes back out, as
     * the mirror image linked to it, dated the day it was voided. Nothing
     * happens for one that was never booked.
     */
    public function invoiceVoided(Invoice $invoice): ?JournalEntry
    {
        return $this->as($invoice, function () use ($invoice) {
            $issued = $this->ledger->postedFor($invoice, 'issued');
            if ($issued === null) {
                return null;
            }

            $lines = $issued->lines()->get()->map(fn ($l) => [
                'account' => (int) $l->ledger_account_id,
                'debit' => (string) $l->credit,
                'credit' => (string) $l->debit,
                'memo' => $l->memo,
            ])->all();

            $entry = $this->post($invoice, 'voided', $invoice->updated_at ?? now(),
                'Invoice '.$invoice->invoice_no.' voided', $lines, $invoice->invoice_no, null);

            if ($issued->reversed_by_id === null) {
                $issued->forceFill(['reversed_by_id' => $entry->id])->save();
                $entry->forceFill(['reverses_id' => $issued->id])->save();
            }

            return $entry;
        });
    }

    public function paymentReceived(Payment $payment): ?JournalEntry
    {
        return $this->as($payment, function () use ($payment) {
            $invoice = $payment->invoice()->withTrashed()->first();
            $book = $this->chart->forMethod($payment->method);

            return $this->post($payment, 'received', $payment->created_at,
                $payment->method->label().' payment'.($invoice ? ' — '.$invoice->invoice_no : ''),
                [
                    ['account' => $book, 'debit' => (string) $payment->amount],
                    ['account' => $this->chart->key('receivable'), 'credit' => (string) $payment->amount],
                ],
                $payment->reference ?? $invoice?->invoice_no, $payment->received_by);
        });
    }

    // ── Cards ────────────────────────────────────────────────────────────

    /**
     * A card moved outside an invoice payment: a top-up, or a charge taken
     * straight off the card at its desk. (A payment by card is posted with
     * the payment; a settlement from an insurer's float with the float.)
     */
    public function cardMoved(CardRecord $record, ?PaymentMethod $paidBy = null): ?JournalEntry
    {
        if ($record->insurance_transaction_id !== null) {
            return null;
        }

        return $this->as($record, function () use ($record, $paidBy) {
            $deposits = $this->chart->key('card_deposits');
            $amount = (string) $record->amount;

            if ($record->type === CardEntryType::Credit) {
                $book = $paidBy !== null && ! in_array($paidBy, [PaymentMethod::Card, PaymentMethod::Insurance], true)
                    ? $this->chart->forMethod($paidBy)
                    : $this->chart->cardTopUpBook();

                return $this->post($record, 'credit', $record->created_at, 'Card top-up'.($record->description ? ' — '.$record->description : ''), [
                    ['account' => $book, 'debit' => $amount],
                    ['account' => $deposits, 'credit' => $amount],
                ], $record->reference, $record->created_by);
            }

            return $this->post($record, 'debit', $record->created_at, 'Card charge'.($record->description ? ' — '.$record->description : ''), [
                ['account' => $deposits, 'debit' => $amount],
                ['account' => $this->chart->key('revenue.other'), 'credit' => $amount],
            ], $record->reference, $record->created_by);
        });
    }

    // ── Insurers ─────────────────────────────────────────────────────────

    public function insuranceMoved(InsuranceTransaction $tx): ?JournalEntry
    {
        return $this->as($tx, function () use ($tx) {
            $floats = $this->chart->key('insurer_floats');
            $amount = (string) $tx->amount;
            $provider = $tx->provider()->first()->name ?? 'Insurer';

            [$memo, $lines] = match ($tx->type) {
                InsuranceEntryType::Deposit => ["Deposit from {$provider}", [
                    ['account' => $this->chart->insurerDepositBook(), 'debit' => $amount],
                    ['account' => $floats, 'credit' => $amount],
                ]],
                InsuranceEntryType::Settlement => ["{$provider} settled a member's card", [
                    ['account' => $floats, 'debit' => $amount],
                    ['account' => $this->chart->key('card_deposits'), 'credit' => $amount],
                ]],
                // A correction to the float with no other side the system can
                // know: it waits in suspense for an accountant to clear.
                InsuranceEntryType::Adjustment => ["{$provider} float adjusted".($tx->notes ? ' — '.$tx->notes : ''),
                    bccomp($amount, '0', 2) >= 0
                        ? [['account' => $this->chart->key('suspense'), 'debit' => $amount], ['account' => $floats, 'credit' => $amount]]
                        : [['account' => $floats, 'debit' => bcmul($amount, '-1', 2)], ['account' => $this->chart->key('suspense'), 'credit' => bcmul($amount, '-1', 2)]],
                ],
            };

            return $this->post($tx, 'posted', $tx->created_at, $memo, $lines, $tx->reference, $tx->created_by, $provider);
        });
    }

    // ── Stock ────────────────────────────────────────────────────────────

    /**
     * The shelf's value moving. Valued at the cost on the movement — what the
     * item was worth at that moment, which the stock ledger keeps on every row
     * for exactly this.
     */
    public function stockMoved(StockMovement $movement): ?JournalEntry
    {
        $value = $movement->costValue();

        if ($value === null || bccomp($value, '0', 2) <= 0) {
            return null;
        }

        return $this->as($movement, function () use ($movement, $value) {
            $inventory = $this->chart->key('inventory');
            $name = $movement->stockItem()->first()->name ?? 'Stock';

            $other = match ($movement->reason) {
                StockMovementReason::OpeningStock => $this->chart->key('opening_equity'),
                StockMovementReason::Received => $this->chart->stockCreditAccount(),
                StockMovementReason::ReturnedToStock, StockMovementReason::Dispensed => $this->chart->key('cogs'),
                StockMovementReason::AdjustmentIn => $this->chart->key('stock_gains'),
                StockMovementReason::ReturnedToSupplier => $this->chart->stockCreditAccount(),
                StockMovementReason::Wastage, StockMovementReason::AdjustmentOut, StockMovementReason::Expired,
                StockMovementReason::Damaged, StockMovementReason::Lost => $this->chart->key('stock_losses'),
            };

            $lines = $movement->reason->isIncoming()
                ? [['account' => $inventory, 'debit' => $value], ['account' => $other, 'credit' => $value]]
                : [['account' => $other, 'debit' => $value], ['account' => $inventory, 'credit' => $value]];

            $qty = rtrim(rtrim((string) $movement->quantity, '0'), '.');

            // A delivery or a return names its supplier and document, so the
            // payable says who is owed and for what (procurement).
            $party = $this->partyOf($movement);

            return $this->post($movement, 'moved', $movement->created_at,
                $movement->reason->label().' — '.$qty.' × '.$name, $lines, $party?->ledgerReference(), $movement->created_by, $party?->ledgerParty());
        });
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    /** The record a stock movement came from, when it can name the other side. */
    private function partyOf(StockMovement $movement): ?\App\Models\Contracts\NamesALedgerParty
    {
        $type = (string) $movement->source_type;

        if ($type === '' || ! is_a($type, \App\Models\Contracts\NamesALedgerParty::class, true) || ! is_a($type, Model::class, true)) {
            return null;
        }

        $source = $type::query()->find($movement->source_id);

        return $source instanceof \App\Models\Contracts\NamesALedgerParty ? $source : null;
    }

    /**
     * Run a posting as the record's own hospital. A record with no hospital
     * (there should be none) is left out of the books rather than posted
     * into somebody else's.
     *
     * @param  \Closure(): ?JournalEntry  $work
     */
    private function as(Model $record, \Closure $work): ?JournalEntry
    {
        $hospitalId = $record->getAttribute('hospital_id');

        if ($hospitalId === null) {
            return null;
        }

        return app(CurrentHospital::class)->within((int) $hospitalId, $work);
    }

    /** @param list<array<string,mixed>> $lines */
    private function post(Model $source, string $event, mixed $date, string $memo, array $lines, ?string $reference, ?int $by, ?string $party = null): JournalEntry
    {
        return $this->ledger->post([
            'date' => $date ?? now(),
            'kind' => JournalKind::System,
            'memo' => $memo,
            'reference' => $reference,
            'party' => $party,
            'source' => $source,
            'event' => $event,
            'by' => $by,
            'guard' => false,
        ], $lines, true);
    }

    /**
     * Where a line of an invoice earns: its service's own account if it has a
     * usable one, pharmacy sales for something off the shelf, and otherwise
     * the account for its kind of work.
     */
    private function incomeAccountFor(InvoiceItem $item, ?OrderItem $line): LedgerAccount
    {
        if ($line === null) {
            return $this->chart->key('revenue.other');
        }

        $own = $line->service?->incomeAccount;
        if ($own instanceof LedgerAccount && $own->is_active && $own->type === \App\Enums\AccountType::Income) {
            return $own;
        }

        if ($line->stock_item_id !== null) {
            return $this->chart->key('revenue.pharmacy');
        }

        return $this->chart->key(match ($line->order?->type) {
            OrderType::Consultation => 'revenue.consultation',
            OrderType::Lab => 'revenue.laboratory',
            OrderType::Imaging => 'revenue.imaging',
            OrderType::Pharmacy => 'revenue.pharmacy',
            OrderType::Procedure => 'revenue.procedures',
            OrderType::Admission => 'revenue.inpatient',
            default => 'revenue.other',
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InvoiceItem>  $items
     * @return array<int, OrderItem>
     */
    private function orderItemsFor(\Illuminate\Support\Collection $items): array
    {
        $ids = $items->where('source_type', (new OrderItem)->getMorphClass())->pluck('source_id')->filter()->all();

        return $ids === []
            ? []
            : OrderItem::query()->whereKey($ids)->with(['order', 'service.incomeAccount'])->get()->keyBy('id')->all();
    }

    private function patientSuffix(Invoice $invoice): string
    {
        $patient = $invoice->patient()->first();

        return $patient ? ' — '.$patient->fullName() : '';
    }
}
