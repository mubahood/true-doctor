<?php

namespace Tests\Feature\Finance;

use App\Enums\JournalKind;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementReason;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\Finance\BooksBackfill;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Services\InsuranceLedgerService;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The hospital's own work reaches its books (docs/finance.md): every money
 * path posts a balanced entry in the same transaction, once, and the
 * statements read back what was posted.
 */
class BooksPostingTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Hospital::factory()->create(['currency' => 'UGX', 'settings' => ['billing' => ['tax_enabled' => true, 'tax_rate' => 10]]]);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function balance(string $key): string
    {
        return app(FinancialStatements::class)->balances(Carbon::now())[app(ChartOfAccounts::class)->key($key)->id];
    }

    private function invoicedVisit(array $prices): \App\Models\Invoice
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        foreach ($prices as $price) {
            $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $price]);
            app(BillingService::class)->orderService($visit, $service->id, 1);
        }

        return app(BillingService::class)->generateInvoice($visit->fresh(), '20.00');
    }

    private function assertEveryEntryBalances(): void
    {
        foreach (JournalEntry::with('lines')->get() as $entry) {
            $this->assertSame(
                0,
                bccomp((string) $entry->lines->sum('debit'), (string) $entry->lines->sum('credit'), 2),
                "{$entry->entry_no} ({$entry->memo}) does not balance",
            );
        }
    }

    public function test_an_invoice_books_income_discount_and_tax_against_receivables(): void
    {
        $invoice = $this->invoicedVisit(['100.00', '50.00']);

        $entry = JournalEntry::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->firstOrFail();
        $this->assertSame(JournalKind::System, $entry->kind);
        $this->assertSame((string) $invoice->total, $this->balance('receivable'));
        $this->assertSame('150.00', $this->balance('revenue.consultation'));
        $this->assertSame('-20.00', $this->balance('discounts'));   // contra-income reads negative
        $this->assertSame((string) $invoice->tax_total, $this->balance('tax.output'));
        $this->assertEveryEntryBalances();
    }

    public function test_payments_land_in_the_book_for_their_method_and_clear_receivables(): void
    {
        $invoice = $this->invoicedVisit(['100.00']);

        app(BillingService::class)->recordPayment($invoice, PaymentMethod::MobileMoney, '50.00', ['reference' => 'MM123']);
        app(BillingService::class)->recordPayment($invoice->fresh(), PaymentMethod::Cash, (string) $invoice->fresh()->balance);

        $this->assertSame('50.00', $this->balance('book.mobile_money'));
        $this->assertSame(bcsub((string) $invoice->total, '50.00', 2), $this->balance('book.cash'));
        $this->assertSame('0.00', $this->balance('receivable'));
        $this->assertEveryEntryBalances();
    }

    public function test_a_card_top_up_and_a_card_payment_are_each_counted_once(): void
    {
        $invoice = $this->invoicedVisit(['100.00']);
        $card = app(CardService::class)->issue($invoice->patient, []);

        app(CardService::class)->credit($card, '200.00', 'Top-up', null, ['method' => PaymentMethod::MobileMoney]);
        app(BillingService::class)->recordPayment($invoice->fresh(), PaymentMethod::Card, (string) $invoice->fresh()->balance, ['card' => $card->fresh()]);

        $this->assertSame('200.00', $this->balance('book.mobile_money'));
        // What is left on the card is what the hospital still holds for the patient.
        $this->assertSame((string) $card->fresh()->balance, $this->balance('card_deposits'));
        $this->assertSame('0.00', $this->balance('receivable'));
        $this->assertSame(0, JournalEntry::where('source_type', (new \App\Models\CardRecord)->getMorphClass())->where('source_event', 'debit')->count());
        $this->assertEveryEntryBalances();
    }

    public function test_an_insurer_float_moves_through_its_own_liability(): void
    {
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $this->hospital->id, 'is_active' => true]);
        app(InsuranceLedgerService::class)->deposit($provider, '1000.00', 'TT-1', null);

        $this->assertSame('1000.00', $this->balance('book.bank'));
        $this->assertSame('1000.00', $this->balance('insurer_floats'));
        $this->assertEveryEntryBalances();
    }

    public function test_stock_is_valued_in_and_costed_out(): void
    {
        $category = StockCategory::create(['name' => 'Tablets', 'unit' => 'tablet', 'is_active' => true]);
        $item = StockItem::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'stock_category_id' => $category->id, 'name' => 'Paracetamol',
            'unit' => 'tablet', 'original_quantity' => 0, 'current_quantity' => 0, 'cost_price' => 10,
            'sale_price' => 25, 'current_stock_value' => 0, 'reorder_level' => 0, 'is_active' => true,
        ]);

        app(StockService::class)->adjust($item, StockMovementReason::OpeningStock, '100');
        app(StockService::class)->receive($item->fresh(), '50', '10.00');
        app(StockService::class)->dispenseOut($item->fresh(), '30');
        app(StockService::class)->adjust($item->fresh(), StockMovementReason::Expired, '20');

        $this->assertSame('1000.00', $this->balance('inventory'));          // 100 tablets left at 10
        $this->assertSame('1000.00', $this->balance('opening_equity'));
        $this->assertSame('500.00', $this->balance('payable'));
        $this->assertSame('300.00', $this->balance('cogs'));
        $this->assertSame('200.00', $this->balance('stock_losses'));
        $this->assertEveryEntryBalances();
    }

    public function test_the_statements_agree_with_each_other(): void
    {
        $invoice = $this->invoicedVisit(['100.00', '40.00']);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '60.00');

        $statements = app(FinancialStatements::class);
        $tb = $statements->trialBalance(Carbon::now());
        $bs = $statements->balanceSheet(Carbon::now());
        $is = $statements->incomeStatement(Carbon::now()->startOfYear(), Carbon::now());

        $this->assertTrue($tb['balanced']);
        $this->assertTrue($bs['balanced']);
        $this->assertSame($is['totals']['net_profit'], $bs['year_profit']);
        $this->assertSame('120.00', $is['totals']['net_revenue']);   // 140 earned less 20 discount
    }

    public function test_backfill_posts_history_once_and_finds_nothing_the_second_time(): void
    {
        $invoice = $this->invoicedVisit(['100.00']);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '10.00');

        // As though these had happened before the hospital had books.
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();

        $backfill = app(BooksBackfill::class);
        $this->assertSame(1, $backfill->pending()['invoices']);
        $this->assertSame(1, $backfill->pending()['payments']);

        $posted = $backfill->run();
        $this->assertSame(1, $posted['invoices']);
        $this->assertSame(1, $posted['payments']);
        $this->assertSame(0, array_sum($backfill->pending()));
        $this->assertSame(0, array_sum($backfill->run()));
        $this->assertEveryEntryBalances();
    }

    public function test_a_hospitals_books_are_its_own(): void
    {
        $this->invoicedVisit(['100.00']);
        $mine = LedgerAccount::count();

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);

        $this->assertSame(0, LedgerAccount::count());
        $this->assertSame(0, JournalLine::count());

        app(ChartOfAccounts::class)->ensure();
        $this->assertSame($mine - $this->practiceAccounts(), LedgerAccount::count() - $this->practiceAccounts());
    }

    private function practiceAccounts(): int
    {
        return count(\App\Support\Practice\Practice::current()->template()->accounts());
    }
}
