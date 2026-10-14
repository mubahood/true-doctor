<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Models\CardRecord;
use App\Models\InsuranceTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Bring the books up to date with everything the hospital did before it had
 * books — and with anything that ever slipped past live posting.
 *
 * It asks one question of each kind of money record — "is there an entry for
 * you?" — and posts the ones without. Because posting is idempotent the whole
 * thing can be run as often as anybody likes; a second run finds nothing to
 * do. The finance overview runs `pending()` to say whether it is needed.
 *
 * Works on the hospital in context; the console command runs it per hospital.
 */
class BooksBackfill
{
    public function __construct(private readonly Bookkeeper $bookkeeper) {}

    /** @return array<string,int> kind => records not yet in the books */
    public function pending(): array
    {
        return [
            'invoices' => $this->unposted($this->invoices(), Invoice::class, 'issued')->count()
                + $this->unvoided()->count(),
            'payments' => $this->unposted(Payment::query(), Payment::class, 'received')->count(),
            'card_records' => $this->unposted($this->cardRecords(), CardRecord::class, null)->count(),
            'insurance' => $this->unposted(InsuranceTransaction::query(), InsuranceTransaction::class, 'posted')->count(),
            'stock' => $this->unposted($this->stockMovements(), StockMovement::class, 'moved')->count(),
        ];
    }

    /** @return array<string,int> kind => entries posted */
    public function run(): array
    {
        $posted = ['invoices' => 0, 'payments' => 0, 'card_records' => 0, 'insurance' => 0, 'stock' => 0];

        // In the order the money moved: an invoice before the payment against it.
        $this->each($this->unposted($this->invoices(), Invoice::class, 'issued'), function (Invoice $i) use (&$posted) {
            $posted['invoices'] += $this->bookkeeper->invoiceIssued($i) ? 1 : 0;
        });
        $this->each($this->unvoided(), function (Invoice $i) use (&$posted) {
            $posted['invoices'] += $this->bookkeeper->invoiceVoided($i) ? 1 : 0;
        });
        $this->each($this->unposted(Payment::query(), Payment::class, 'received'), function (Payment $p) use (&$posted) {
            $posted['payments'] += $this->bookkeeper->paymentReceived($p) ? 1 : 0;
        });
        $this->each($this->unposted($this->cardRecords(), CardRecord::class, null), function (CardRecord $r) use (&$posted) {
            $posted['card_records'] += $this->bookkeeper->cardMoved($r) ? 1 : 0;
        });
        $this->each($this->unposted(InsuranceTransaction::query(), InsuranceTransaction::class, 'posted'), function (InsuranceTransaction $t) use (&$posted) {
            $posted['insurance'] += $this->bookkeeper->insuranceMoved($t) ? 1 : 0;
        });
        $this->each($this->unposted($this->stockMovements(), StockMovement::class, 'moved'), function (StockMovement $m) use (&$posted) {
            $posted['stock'] += $this->bookkeeper->stockMoved($m) ? 1 : 0;
        });

        return $posted;
    }

    /** Invoices that should be in the books as issued. @return Builder<Invoice> */
    private function invoices(): Builder
    {
        return Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value]);
    }

    /**
     * Void invoices whose issue was booked and whose voiding was not.
     *
     * @return Builder<Invoice>
     */
    private function unvoided(): Builder
    {
        $morph = (new Invoice)->getMorphClass();
        $posted = fn (string $event) => fn ($q) => $q->selectRaw('1')->from('journal_entries')
            ->whereColumn('journal_entries.source_id', 'invoices.id')
            ->where('journal_entries.source_type', $morph)
            ->where('journal_entries.source_event', $event);

        return Invoice::query()
            ->where('status', InvoiceStatus::Void->value)
            ->whereExists($posted('issued'))
            ->whereNotExists($posted('voided'));
    }

    /**
     * A card's own movements — not the debits a payment made (posted with the
     * payment) and not the credits an insurer's float paid for (posted with
     * the float).
     *
     * @return Builder<CardRecord>
     */
    private function cardRecords(): Builder
    {
        return CardRecord::query()
            ->whereNull('insurance_transaction_id')
            ->whereNotIn('id', Payment::query()->whereNotNull('card_record_id')->select('card_record_id'));
    }

    /** Only movements with a value; a free item moves nothing in the books. @return Builder<StockMovement> */
    private function stockMovements(): Builder
    {
        return StockMovement::query()->where('unit_cost', '>', 0);
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  class-string<T>  $class
     * @return Builder<T>
     */
    private function unposted(Builder $query, string $class, ?string $event): Builder
    {
        $model = new $class;

        return $query->whereNotExists(function ($q) use ($model, $event) {
            $q->selectRaw('1')
                ->from((new JournalEntry)->getTable())
                ->whereColumn('journal_entries.source_id', $model->qualifyColumn('id'))
                ->where('journal_entries.source_type', $model->getMorphClass())
                ->when($event !== null, fn ($w) => $w->where('journal_entries.source_event', $event));
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  \Closure  $post  called with each record
     */
    private function each(Builder $query, \Closure $post): void
    {
        $query->chunkById(200, function ($rows) use ($post) {
            foreach ($rows as $row) {
                $post($row);
            }
        });
    }
}
