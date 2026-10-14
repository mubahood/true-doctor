<?php

namespace Tests\Feature\Finance;

use App\Enums\JournalKind;
use App\Exceptions\ClosedPeriodException;
use App\Livewire\Finance\Accounts;
use App\Livewire\Finance\Journal;
use App\Livewire\Finance\Overview;
use App\Livewire\Finance\Settings;
use App\Livewire\Finance\Statements;
use App\Livewire\Finance\Transactions;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Services\Finance\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The finance pages (docs/finance.md): who may open them, what a person at a
 * desk can record, and that what they record is in the books and the
 * statements straight away.
 */
class FinancePagesTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital();
        $this->actingAsRole('accountant', $this->hospital);
    }

    private function account(string $key): LedgerAccount
    {
        return app(ChartOfAccounts::class)->key($key);
    }

    private function expense(string $name = 'Rent'): LedgerAccount
    {
        app(ChartOfAccounts::class)->ensure();

        return LedgerAccount::where('name', $name)->firstOrFail();
    }

    private function balance(LedgerAccount $account): string
    {
        return app(FinancialStatements::class)->balances(Carbon::now())[$account->id];
    }

    public function test_every_page_opens_for_an_accountant(): void
    {
        foreach (['admin.finance.index', 'admin.finance.transactions', 'admin.finance.journal', 'admin.finance.accounts', 'admin.finance.statements', 'admin.finance.settings'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('admin.finance.accounts.show', $this->account('book.cash')))->assertOk()->assertSee('Cash at hand');

        foreach (['income', 'balance', 'trial', 'cashflow'] as $report) {
            $this->get(route('admin.finance.statements', ['report' => $report]))->assertOk();
        }
    }

    public function test_staff_without_finance_access_are_refused(): void
    {
        $this->actingAsRole('nurse', $this->hospital);

        $this->get(route('admin.finance.index'))->assertForbidden();
        $this->get(route('admin.finance.statement-pdf'))->assertForbidden();
        Livewire::test(Transactions::class)->assertForbidden();
    }

    public function test_money_in_and_money_out_post_to_the_book_and_the_category(): void
    {
        $cash = $this->account('book.cash');
        $rent = $this->expense('Rent');
        $grant = $this->expense('Grants & donations');

        Livewire::test(Transactions::class)
            ->call('openMoney', 'in')
            ->set('moneyBook', (string) $cash->id)
            ->set('moneyAccount', (string) $grant->id)
            ->set('moneyAmount', '500000')
            ->set('moneyMemo', 'Rotary donation')
            ->set('moneyParty', 'Rotary Club')
            ->call('saveMoney')
            ->assertHasNoErrors()
            ->assertSet('showMoney', false);

        Livewire::test(Transactions::class)
            ->call('openMoney', 'out')
            ->set('moneyBook', (string) $cash->id)
            ->set('moneyAccount', (string) $rent->id)
            ->set('moneyAmount', '200000')
            ->set('moneyMemo', 'October rent')
            ->call('saveMoney')
            ->assertHasNoErrors();

        $this->assertSame('300000.00', $this->balance($cash));
        $this->assertSame('200000.00', $this->balance($rent));
        $this->assertSame('500000.00', $this->balance($grant));
        $this->assertSame(1, JournalEntry::where('kind', JournalKind::Income->value)->where('party', 'Rotary Club')->count());
    }

    public function test_a_transfer_moves_money_between_books_and_earns_nothing(): void
    {
        $cash = $this->account('book.cash');
        $bank = $this->account('book.bank');

        Livewire::test(Transactions::class)
            ->call('openMoney', 'transfer')
            ->set('moneyFromBook', (string) $cash->id)
            ->set('moneyToBook', (string) $bank->id)
            ->set('moneyAmount', '75000')
            ->call('saveMoney')
            ->assertHasNoErrors();

        $this->assertSame('-75000.00', $this->balance($cash));
        $this->assertSame('75000.00', $this->balance($bank));
        $this->assertSame('0.00', app(FinancialStatements::class)->incomeStatement(now()->startOfYear(), now())['totals']['net_profit']);
    }

    public function test_a_book_cannot_be_its_own_category(): void
    {
        $cash = $this->account('book.cash');

        Livewire::test(Transactions::class)
            ->call('openMoney', 'in')
            ->set('moneyBook', (string) $cash->id)
            ->set('moneyAccount', (string) $this->account('book.bank')->id)
            ->set('moneyAmount', '10')
            ->set('moneyMemo', 'x')
            ->call('saveMoney')
            ->assertHasErrors('moneyAccount');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_closed_period_refuses_a_backdated_entry(): void
    {
        FinancialYear::create(['name' => 'FY 2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31', 'status' => 'closed']);

        $this->expectException(ClosedPeriodException::class);

        app(CashbookService::class)->moneyOut([
            'date' => '2025-06-01', 'book_id' => $this->account('book.cash')->id, 'account_id' => $this->expense('Rent')->id,
            'amount' => '100', 'memo' => 'Backdated',
        ]);
    }

    public function test_a_journal_must_balance_and_then_posts(): void
    {
        $equipment = $this->expense('Medical equipment');
        $depreciation = $this->expense('Depreciation');
        $accumulated = $this->expense('Accumulated depreciation');

        Livewire::test(Journal::class)
            ->call('newJournal')
            ->set('jMemo', 'Depreciation, October')
            ->set('jLines', [
                ['account_id' => (string) $depreciation->id, 'debit' => '50000', 'credit' => null, 'memo' => null],
                ['account_id' => (string) $accumulated->id, 'debit' => null, 'credit' => '40000', 'memo' => null],
            ])
            ->call('saveJournal')
            ->assertHasErrors('jLines')
            ->set('jLines.1.credit', '50000')
            ->call('saveJournal')
            ->assertHasNoErrors()
            ->assertSet('showJournal', false);

        $this->assertSame('50000.00', $this->balance($depreciation));
        $this->assertSame('0.00', $this->balance($equipment));
    }

    public function test_opening_balances_balance_themselves_against_equity(): void
    {
        $cash = $this->account('book.cash');
        $bank = $this->account('book.bank');
        $loan = $this->expense('Loans');

        Livewire::test(Journal::class)
            ->call('newOpening')
            ->set('oBalances', [$cash->id => '100000', $bank->id => '2500000', $loan->id => '1000000'])
            ->call('saveOpening')
            ->assertHasNoErrors();

        $this->assertSame('1600000.00', $this->balance($this->account('opening_equity')));
        $this->assertTrue(app(FinancialStatements::class)->balanceSheet(now())['balanced']);
    }

    public function test_reversing_an_entry_takes_it_back_out_and_keeps_both(): void
    {
        $entry = app(CashbookService::class)->moneyOut([
            'date' => now()->toDateString(), 'book_id' => $this->account('book.cash')->id,
            'account_id' => $this->expense('Rent')->id, 'amount' => '900', 'memo' => 'Recorded twice',
        ]);

        Livewire::test(Journal::class)
            ->call('peekEntry', $entry->id)
            ->set('reverseReason', 'Duplicate')
            ->call('reverseEntry')
            ->assertHasNoErrors();

        $this->assertNotNull($entry->fresh()->reversed_by_id);
        $this->assertSame('0.00', $this->balance($this->expense('Rent')));
        $this->assertSame(2, JournalEntry::count());

        $this->expectException(\DomainException::class);
        app(LedgerService::class)->reverse($entry->fresh(), 'Again');
    }

    public function test_an_entry_the_system_posted_cannot_be_reversed_by_hand(): void
    {
        $entry = app(LedgerService::class)->post([
            'date' => now(), 'kind' => JournalKind::System, 'memo' => 'Invoice', 'guard' => false,
        ], [
            ['account' => $this->account('receivable'), 'debit' => '10'],
            ['account' => $this->account('revenue.other'), 'credit' => '10'],
        ]);

        $this->expectException(\DomainException::class);
        app(LedgerService::class)->reverse($entry, 'No');
    }

    public function test_the_hospital_adds_its_own_categories_but_cannot_switch_off_a_system_one(): void
    {
        Livewire::test(Accounts::class)
            ->call('create', 'expense')
            ->set('name', 'Generator fuel')
            ->call('save')
            ->assertHasNoErrors();

        $fuel = LedgerAccount::where('name', 'Generator fuel')->firstOrFail();
        $this->assertSame('expense', $fuel->type->value);

        Livewire::test(Accounts::class)
            ->call('edit', $this->account('receivable')->id)
            ->set('is_active', false)
            ->call('save')
            ->assertHasErrors('is_active');

        Livewire::test(Accounts::class)->call('delete', $fuel->id);
        $this->assertNull(LedgerAccount::find($fuel->id));
    }

    public function test_another_hospitals_books_are_not_reachable(): void
    {
        $other = $this->hospital();
        $theirs = app(\App\Support\CurrentHospital::class)->within($other->id, function () {
            app(ChartOfAccounts::class)->ensure();

            return LedgerAccount::where('system_key', 'book.cash')->firstOrFail();
        });

        $this->get(route('admin.finance.accounts.show', $theirs->id))->assertNotFound();

        $this->expectException(\DomainException::class);
        app(CashbookService::class)->moneyIn([
            'date' => now()->toDateString(), 'book_id' => $theirs->id,
            'account_id' => $this->expense('Rent')->id, 'amount' => '1', 'memo' => 'x',
        ]);
    }

    public function test_settings_route_a_payment_method_to_a_chosen_book(): void
    {
        $momo2 = LedgerAccount::create(['code' => '1031', 'name' => 'Airtel Money', 'type' => 'asset', 'subtype' => 'mobile_money', 'is_book' => true, 'is_active' => true]);

        Livewire::test(Settings::class)
            ->set('methodBooks.mobile_money', (string) $momo2->id)
            ->call('save')
            ->assertHasNoErrors();

        app(ChartOfAccounts::class)->forget();
        $this->assertSame($momo2->id, app(ChartOfAccounts::class)->forMethod(\App\Enums\PaymentMethod::MobileMoney)->id);
    }

    public function test_statements_render_and_download(): void
    {
        app(CashbookService::class)->moneyIn([
            'date' => now()->toDateString(), 'book_id' => $this->account('book.cash')->id,
            'account_id' => $this->expense('Other income')->id, 'amount' => '1234', 'memo' => 'Sundry',
        ]);

        Livewire::test(Statements::class)->assertSee('Income statement')->assertSee('Other income')
            ->call('show', 'balance')->assertSee('Balances')
            ->call('show', 'trial')->assertSee('Totals')
            ->call('show', 'cashflow')->assertSee('Money in');

        $csv = $this->get(route('admin.finance.export', ['what' => 'statement', 'report' => 'trial']));
        $csv->assertOk();
        $this->assertStringContainsString('1234.00', $csv->streamedContent());

        $this->get(route('admin.finance.export', ['what' => 'transactions']))->assertOk();
        $this->get(route('admin.finance.export', ['what' => 'ledger', 'account' => $this->account('book.cash')->id]))->assertOk();
        $this->get(route('admin.finance.statement-pdf', ['report' => 'balance']))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_overview_offers_to_bring_history_into_the_books(): void
    {
        Livewire::test(Overview::class)->assertOk()->assertSee('Cash in your books')->call('catchUp')->assertDispatched('toast');
    }
}
