<?php

namespace Tests\Feature\Api;

use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The finance area of the API: authorised like the web pages (finance.view /
 * record / manage), reading the same services, and keeping the books balanced.
 */
class FinanceApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private function as(string $role, ?Hospital $h = null): User
    {
        $this->seedRbac();
        $h ??= $this->hospital;
        $this->withHospital($h);
        $user = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $user->syncSpatieRole();
        Sanctum::actingAs($user);

        return $user;
    }

    private function key(string $key): LedgerAccount
    {
        return app(ChartOfAccounts::class)->key($key);
    }

    private function expense(): LedgerAccount
    {
        return LedgerAccount::create(['code' => '6777', 'name' => 'Generator fuel', 'type' => 'expense', 'subtype' => 'operating_expense']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital(['currency' => 'UGX']);
        $this->withHospital($this->hospital);
        app(ChartOfAccounts::class)->ensure();
    }

    public function test_money_in_out_and_transfer_are_recorded_balanced_and_match_the_web_services(): void
    {
        $this->as('accountant');
        $cash = $this->key('book.cash');
        $bank = $this->key('book.bank');
        $rent = $this->expense();
        $income = $this->key('revenue.consultation');
        $today = Carbon::today()->toDateString();

        $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => $today, 'book_id' => $cash->id, 'account_id' => $income->id, 'amount' => '500000', 'memo' => 'Grant'])
            ->assertCreated()->assertJsonPath('data.kind', 'income')->assertJsonPath('data.total', '500000.00');
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'out', 'date' => $today, 'book_id' => $cash->id, 'account_id' => $rent->id, 'amount' => '120000.50', 'memo' => 'Fuel', 'party' => 'Shell'])
            ->assertCreated()->assertJsonPath('data.kind', 'expense');
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'transfer', 'date' => $today, 'from_book_id' => $cash->id, 'to_book_id' => $bank->id, 'amount' => '100000'])
            ->assertCreated()->assertJsonPath('data.kind', 'transfer');

        // The books still balance, and the API's figures are the service's own.
        $trial = app(FinancialStatements::class)->trialBalance(Carbon::now());
        $this->assertTrue($trial['balanced']);
        $this->assertSame($trial['debit'], $trial['credit']);

        $overview = $this->getJson('/api/v1/finance')->assertOk();
        $f = app(FinancialStatements::class)->overview(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());
        $overview->assertJsonPath('data.cash', $f['cash'])->assertJsonPath('data.cash', '379999.50');
        $overview->assertJsonPath('data.income_statement.totals.3.amount', $f['statement']['totals']['net_profit']);
        $overview->assertJsonCount(6, 'data.trend')->assertJsonPath('data.pending.invoices', 0);

        // The cashbook filtered to one book reads like that book's statement.
        $rows = $this->getJson('/api/v1/finance/transactions?book='.$cash->id)->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.book_balance', '279999.50')
            ->assertJsonPath('meta.page_in', '500000.00')->assertJsonPath('meta.page_out', '220000.50');
        $this->assertCount(3, $rows->json('data'));
        $this->getJson('/api/v1/finance/transactions?book='.$cash->id.'&dir=out')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/finance/transactions?kind=transfer')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/finance/transactions?q=Shell')->assertJsonPath('meta.total', 1);
    }

    public function test_validation_and_the_service_rules_answer_in_the_envelope(): void
    {
        $this->as('accountant');
        $cash = $this->key('book.cash');
        $income = $this->key('revenue.consultation');
        $today = Carbon::today()->toDateString();

        $this->postJson('/api/v1/finance/transactions', ['date' => $today, 'amount' => 5])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['kind']);
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => $today, 'book_id' => $cash->id, 'account_id' => $income->id, 'amount' => 0, 'memo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        // A category that is not a category.
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => $today, 'book_id' => $cash->id, 'account_id' => $cash->id, 'amount' => 5, 'memo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['account_id']);
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'transfer', 'date' => $today, 'from_book_id' => $cash->id, 'to_book_id' => $cash->id, 'amount' => 5])->assertStatus(422);

        // A closed period refuses a posting dated inside it.
        FinancialYear::create(['name' => 'FY', 'starts_on' => Carbon::today()->startOfYear()->toDateString(), 'ends_on' => Carbon::today()->endOfYear()->toDateString(), 'status' => 'closed']);
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => $today, 'book_id' => $cash->id, 'account_id' => $income->id, 'amount' => 5, 'memo' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_roles_without_the_permission_are_denied_like_the_web(): void
    {
        $cash = $this->key('book.cash');
        $income = $this->key('revenue.consultation');

        foreach (['receptionist', 'doctor', 'nurse'] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/finance')->assertForbidden();
            $this->getJson('/api/v1/finance/transactions')->assertForbidden();
            $this->getJson('/api/v1/finance/statements')->assertForbidden();
            $this->getJson('/api/v1/finance/accounts')->assertForbidden();
            $this->getJson('/api/v1/finance/journal')->assertForbidden();
            $this->getJson('/api/v1/financial-years')->assertForbidden();
            $this->getJson('/api/v1/finance/settings')->assertForbidden();
            $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => today()->toDateString(), 'book_id' => $cash->id, 'account_id' => $income->id, 'amount' => 5, 'memo' => 'x'])->assertForbidden();
        }
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_statements_are_structured_and_agree_with_the_service_and_pdf_is_served(): void
    {
        $this->as('accountant');
        $cash = $this->key('book.cash');
        $income = $this->key('revenue.consultation');
        $today = Carbon::today()->toDateString();
        app(CashbookService::class)->moneyIn(['date' => $today, 'book_id' => $cash->id, 'account_id' => $income->id, 'amount' => '90000', 'memo' => 'Fees'], auth()->id());

        $svc = app(FinancialStatements::class);
        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now()->endOfMonth();

        $is = $this->getJson('/api/v1/finance/statements?report=income&preset=month&vs=previous')->assertOk();
        $is->assertJsonPath('data.report', 'income')->assertJsonPath('data.statement.title', 'Income statement');
        $expected = $svc->incomeStatement($from, $to);
        $this->assertSame($expected['totals']['net_profit'], collect($is->json('data.statement.totals'))->firstWhere('key', 'net_profit')['amount']);
        $this->assertSame('90000.00', collect($is->json('data.statement.sections'))->firstWhere('key', 'revenue')['total']);
        $this->assertNotNull($is->json('data.compare'));

        $bs = $this->getJson('/api/v1/finance/statements?report=balance')->assertOk();
        $this->assertTrue($bs->json('data.statement.balanced'));
        $this->assertSame($svc->balanceSheet($to)['assets'], collect($bs->json('data.statement.totals'))->firstWhere('key', 'assets')['amount']);

        $tb = $this->getJson('/api/v1/finance/statements?report=trial')->assertOk();
        $this->assertTrue($tb->json('data.statement.balanced'));
        $this->assertSame($tb->json('data.statement.totals.0.amount'), $tb->json('data.statement.totals.1.amount'));

        $cf = $this->getJson('/api/v1/finance/statements?report=cashflow')->assertOk();
        $this->assertSame($svc->cashFlow($from, $to)['closing'], collect($cf->json('data.statement.totals'))->firstWhere('key', 'closing')['amount']);
        $this->assertSame('90000.00', $cf->json('data.statement.sections.0.rows.0.amount'));

        // An unknown report falls back to the income statement, as the page does.
        $this->getJson('/api/v1/finance/statements?report=nonsense')->assertJsonPath('data.report', 'income');

        $this->get('/api/v1/finance/statements/pdf?report=income')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/v1/finance/export?what=transactions')->assertOk();
    }

    public function test_journal_chart_reversal_and_opening_balances(): void
    {
        $this->as('accountant');
        $cash = $this->key('book.cash');
        $fuel = $this->expense();
        $today = Carbon::today()->toDateString();

        // The chart: a new account, kept off when switched off, deleted only while unused.
        $new = $this->postJson('/api/v1/finance/accounts', ['code' => '6778', 'name' => 'Locum doctors', 'type' => 'expense', 'is_active' => true])->assertCreated()->json('data');
        $this->postJson('/api/v1/finance/accounts', ['code' => '6778', 'name' => 'Dup', 'type' => 'expense'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->putJson('/api/v1/finance/accounts/'.$new['id'], ['code' => '6778', 'name' => 'Locums', 'type' => 'expense', 'is_active' => true])->assertOk()->assertJsonPath('data.name', 'Locums');
        $this->postJson('/api/v1/finance/accounts/'.$new['id'].'/toggle')->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/v1/finance/accounts/'.$this->key('receivable')->id.'/toggle')->assertStatus(422);
        $this->deleteJson('/api/v1/finance/accounts/'.$new['id'])->assertOk();
        $this->assertNull(LedgerAccount::find($new['id']));

        $tree = $this->getJson('/api/v1/finance/accounts')->assertOk();
        $this->assertContains('asset', collect($tree->json('data'))->pluck('type')->all());
        $this->assertNotNull($tree->json('data.0.accounts.0.balance'));

        // A journal must balance; one that does is posted and can be reversed once.
        $this->postJson('/api/v1/finance/journal', ['date' => $today, 'memo' => 'Unbalanced', 'lines' => [['account_id' => $fuel->id, 'debit' => 100], ['account_id' => $cash->id, 'credit' => 90]]])->assertStatus(422);
        $posted = $this->postJson('/api/v1/finance/journal', ['date' => $today, 'memo' => 'Accrual', 'lines' => [['account_id' => $fuel->id, 'debit' => 100], ['account_id' => $cash->id, 'credit' => 100]]])->assertCreated()->json('data');
        $this->assertCount(2, $posted['lines']);

        $this->getJson('/api/v1/finance/journal?kind=journal')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/finance/journal/'.$posted['uuid'])->assertOk()->assertJsonPath('data.can_be_reversed', true);
        $this->postJson('/api/v1/finance/journal/'.$posted['uuid'].'/reverse', [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson('/api/v1/finance/journal/'.$posted['uuid'].'/reverse', ['reason' => 'Wrong month'])->assertCreated()->assertJsonPath('data.kind', 'reversal');
        $this->postJson('/api/v1/finance/journal/'.$posted['uuid'].'/reverse', ['reason' => 'Again'])->assertStatus(422);
        $this->assertTrue(app(FinancialStatements::class)->trialBalance(Carbon::now())['balanced']);

        // The account's own ledger: opening, lines with running balance, closing.
        $led = $this->getJson('/api/v1/finance/accounts/'.$fuel->id)->assertOk();
        $led->assertJsonPath('data.closing', '0.00')->assertJsonCount(2, 'data.lines');

        $this->postJson('/api/v1/finance/journal/opening', ['date' => $today, 'balances' => [(string) $cash->id => '75000']])->assertCreated()->assertJsonPath('data.kind', 'opening');
        $this->getJson('/api/v1/finance/options')->assertOk()->assertJsonPath('data.has_opening', true);
    }

    public function test_settings_read_and_update_and_reject_a_foreign_book(): void
    {
        $this->as('accountant');
        $bank = $this->key('book.bank');
        $expense = $this->expense();

        $this->getJson('/api/v1/finance/settings')->assertOk()->assertJsonPath('data.card_topup_book', null);
        $this->putJson('/api/v1/finance/settings', ['card_topup_book' => $bank->id, 'method_books' => ['cash' => $bank->id]])
            ->assertOk()->assertJsonPath('data.card_topup_book', $bank->id);
        $this->assertSame($bank->id, app(ChartOfAccounts::class)->cardTopUpBook()->id);
        $this->putJson('/api/v1/finance/settings', ['card_topup_book' => $expense->id])->assertStatus(422)->assertJsonValidationErrors(['card_topup_book']);
        $this->putJson('/api/v1/finance/settings', ['card_topup_book' => null])->assertOk();
        $this->assertSame($this->key('book.cash')->id, app(ChartOfAccounts::class)->cardTopUpBook()->id);
    }

    public function test_financial_years_open_close_and_reopen_and_closing_blocks_postings(): void
    {
        $this->as('accountant');
        $cash = $this->key('book.cash');
        $income = $this->key('revenue.consultation');
        $year = (int) now()->format('Y');

        $fy = $this->postJson('/api/v1/financial-years', ['name' => "FY $year", 'starts_on' => "$year-01-01", 'ends_on' => "$year-12-31"])->assertCreated()->assertJsonPath('data.is_open', true)->json('data');
        $this->postJson('/api/v1/financial-years', ['name' => 'Overlap', 'starts_on' => "$year-06-01", 'ends_on' => "$year-08-01"])->assertStatus(422);
        $this->postJson('/api/v1/financial-years', ['name' => "FY $year", 'starts_on' => ($year + 5).'-01-01', 'ends_on' => ($year + 5).'-12-31'])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->getJson('/api/v1/financial-years?q=FY')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/financial-years/'.$fy['id'])->assertOk()->assertJsonStructure(['data' => ['report' => ['payments_total', 'invoices_total', 'outstanding', 'by_method']]]);

        $this->postJson('/api/v1/financial-years/'.$fy['id'].'/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson('/api/v1/financial-years/'.$fy['id'].'/close')->assertStatus(422);
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => today()->toDateString(), 'book_id' => $cash->id, 'account_id' => $income->id, 'amount' => 5, 'memo' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/financial-years/'.$fy['id'].'/reopen')->assertOk()->assertJsonPath('data.is_open', true);
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => today()->toDateString(), 'book_id' => $cash->id, 'account_id' => $income->id, 'amount' => 5, 'memo' => 'x'])->assertCreated();

        // Reading is finance.view; opening/closing is finance.manage - same split as FinancialYearPolicy.
        $this->as('doctor');
        $this->postJson('/api/v1/financial-years/'.$fy['id'].'/close')->assertForbidden();
        $this->postJson('/api/v1/financial-years', ['name' => 'Next', 'starts_on' => ($year + 1).'-01-01', 'ends_on' => ($year + 1).'-12-31'])->assertForbidden();
    }

    public function test_another_hospitals_books_are_invisible(): void
    {
        // A foreign hospital with its own chart, entry and year.
        $other = $this->hospital(['currency' => 'UGX']);
        $this->withHospital($other);
        app(ChartOfAccounts::class)->forget();
        app(ChartOfAccounts::class)->ensure();
        $oCash = $this->key('book.cash');
        $oIncome = $this->key('revenue.consultation');
        $entry = app(CashbookService::class)->moneyIn(['date' => today()->toDateString(), 'book_id' => $oCash->id, 'account_id' => $oIncome->id, 'amount' => '777', 'memo' => 'Theirs'], null);
        $oYear = FinancialYear::create(['name' => 'Theirs', 'starts_on' => '2030-01-01', 'ends_on' => '2030-12-31', 'status' => 'open']);

        app(ChartOfAccounts::class)->forget();
        $this->as('accountant');   // back in our hospital

        $this->getJson('/api/v1/finance/journal/'.$entry->uuid)->assertNotFound();
        $this->postJson('/api/v1/finance/journal/'.$entry->uuid.'/reverse', ['reason' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/finance/accounts/'.$oCash->id)->assertNotFound();
        $this->putJson('/api/v1/finance/accounts/'.$oCash->id, ['code' => '1000', 'name' => 'X', 'type' => 'asset'])->assertNotFound();
        $this->getJson('/api/v1/financial-years/'.$oYear->id)->assertNotFound();
        $this->postJson('/api/v1/financial-years/'.$oYear->id.'/close')->assertNotFound();
        $this->getJson('/api/v1/finance/transactions')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/finance/journal')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/financial-years')->assertJsonPath('meta.total', 0);
        // Posting to the other hospital's book is refused, not accepted.
        $this->postJson('/api/v1/finance/transactions', ['kind' => 'in', 'date' => today()->toDateString(), 'book_id' => $oCash->id, 'account_id' => $this->key('revenue.consultation')->id, 'amount' => 5, 'memo' => 'x'])->assertStatus(422);
        $this->assertSame(app(CurrentHospital::class)->id(), $this->hospital->id);
    }
}
