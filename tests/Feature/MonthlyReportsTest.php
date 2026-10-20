<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Livewire\Reports\Monthly;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\LedgerAccount;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CashierDaySummary;
use App\Services\DebtorsService;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Services\Finance\LedgerService;
use App\Services\InvoiceCreditService;
use App\Services\Reports\ExpenseReport;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\MonthlyReport;
use App\Services\Reports\MonthlyReports;
use App\Services\Reports\PatientRecordsReport;
use App\Services\Reports\ReceivablesReport;
use App\Services\Reports\ReportPeriod;
use App\Services\StockService;
use App\Support\CurrentHospital;
use App\Support\Imports\SpreadsheetReader;
use App\Support\Imports\XlsxWriter;
use Database\Seeders\DemoScenarios\MonthlyReports as MonthlyReportsScenario;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Monthly reports (docs/reports.md): income, expenses, accounts receivable and
 * patient records. The totals agree with the cashier's day, the books and the
 * debtors list; one hospital never sees another's; only reports.view opens
 * them; and every export opens.
 */
class MonthlyReportsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $admin;

    private User $doctor;

    /** The tenth of this month: the day the fixtures count from. */
    private Carbon $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        // The tenth of this month, mid-morning: every fixture is placed from here.
        $this->anchor = Carbon::now()->startOfMonth()->addDays(9)->setTime(10, 0);
        $this->travelTo($this->anchor);

        $this->h = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->h->id);
        $this->admin = $this->staff('hospital_admin');
        $this->doctor = $this->staff('doctor', ['name' => 'Dr Okello Peter']);
        $this->actingAs($this->admin);
    }

    /** @param array<string,mixed> $attrs */
    private function staff(string $role, array $attrs = [], ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role] + $attrs);
        $u->syncSpatieRole();

        return $u;
    }

    private function at(Carbon $when): void
    {
        $this->travelTo($when);
    }

    private function day(int $day, int $monthsBack = 0): Carbon
    {
        return $this->anchor->copy()->subMonthsNoOverflow($monthsBack)->startOfMonth()->addDays($day - 1)->setTime(10, 0);
    }

    /** @param list<string> $prices */
    private function bill(array $prices, ?Patient $patient = null, ?Hospital $h = null, ?string $diagnosis = null): Invoice
    {
        $h ??= $this->h;
        app(CurrentHospital::class)->set($h->id);
        $patient ??= Patient::factory()->create(['hospital_id' => $h->id]);
        $visit = Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'status' => 'ongoing',
            'doctor_user_id' => $h->is($this->h) ? $this->doctor->id : null, 'diagnosis' => $diagnosis,
        ]);
        foreach ($prices as $i => $p) {
            $name = ['Extraction', 'Scaling and polishing', 'X-ray'][$i] ?? 'Service '.$i;
            $service = Service::query()->where('name', $name)->first()
                ?? Service::factory()->create(['hospital_id' => $h->id, 'price' => $p, 'name' => $name]);
            $service->update(['price' => $p]);
            app(BillingService::class)->orderService($visit->fresh(), $service->id, 1);
        }

        return app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
    }

    private function pay(Invoice $invoice, string $amount, PaymentMethod $method = PaymentMethod::Cash): void
    {
        app(BillingService::class)->recordPayment($invoice->fresh(), $method, $amount, ['reference' => 'MM-'.$invoice->id], null);
    }

    private function period(int $monthsBack = 0): ReportPeriod
    {
        return app(MonthlyReports::class)->period($this->anchor->copy()->subMonthsNoOverflow($monthsBack)->format('Y-m'));
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function table(array $summary, string $key): array
    {
        foreach ($summary['tables'] as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        $this->fail("no table {$key}");
    }

    /** @return list<array<string,string|int|null>> */
    private function rows(MonthlyReport $report, ReportPeriod $p): array
    {
        return iterator_to_array($report->rows($p), false);
    }

    // ── Income ───────────────────────────────────────────────────────────

    private function incomeMonth(): void
    {
        $this->at($this->day(20, 1));
        $this->pay($this->bill(['5000.00']), '5000.00');                  // last month

        $this->at($this->day(3));
        $a = $this->bill(['30000.00', '20000.00']);
        $this->pay($a, '30000.00');
        $this->at($this->day(5));
        $this->pay($a, '20000.00', PaymentMethod::MobileMoney);
        $this->pay($this->bill(['10000.00']), '10000.00');

        $this->at($this->day(7));
        $accounts = $this->staff('accountant');
        $credits = app(InvoiceCreditService::class);
        $credits->requestCreditNote($a->fresh(), '10000', 'X-ray not done.', $accounts);
        $credits->requestRefund($a->fresh(), PaymentMethod::Cash, '10000', 'Cash back for the X-ray.', $accounts);

        $this->at($this->day(10));
    }

    public function test_income_is_the_sum_of_the_cashiers_days(): void
    {
        $this->incomeMonth();
        $p = $this->period();
        $report = app(IncomeReport::class);

        $t = $report->headline($p);
        $this->assertSame('60000.00', $t['received']);
        $this->assertSame('10000.00', $t['refunded']);
        $this->assertSame('50000.00', $t['net']);
        $this->assertSame('10000.00', $t['credited']);
        $this->assertSame(3, $t['payments']);

        // The month is its cashier's days added up, to the cent.
        $cashier = app(CashierDaySummary::class);
        $received = '0.00';
        $net = '0.00';
        foreach ($p->days() as $day) {
            $d = $cashier->forDate($this->h->fresh(), $day);
            $received = bcadd($received, $d['total'], 2);
            $net = bcadd($net, $d['net'], 2);
        }
        $this->assertSame($t['received'], $received);
        $this->assertSame($t['net'], $net);

        // The detailed table: three payments and the refund as a negative line, adding up to the net.
        $rows = $this->rows($report, $p);
        $this->assertCount(4, $rows);
        $this->assertSame($report->count($p), count($rows));
        $this->assertSame('50000.00', array_reduce($rows, fn ($c, $r) => bcadd($c, (string) $r['amount'], 2), '0.00'));
        $refund = collect($rows)->firstWhere('amount', '-10000.00');
        $this->assertNotNull($refund);
        $this->assertStringStartsWith('RF-', (string) $refund['receipt_no']);
        $this->assertSame('Dr Okello Peter', $rows[0]['clinician']);
        $this->assertStringContainsString('Extraction', (string) $rows[0]['services']);

        $summary = $report->summary($p);
        $methods = collect($this->table($summary, 'by_method')['rows'])->keyBy('method');
        $this->assertSame('30000.00', $methods['Cash']['net']);           // 30,000 + 10,000 less the 10,000 refunded
        $this->assertSame('20000.00', $methods['Mobile money']['net']);

        // Shared across services, the shares add up to what was received.
        $services = $this->table($summary, 'by_service')['rows'];
        $this->assertSame('60000.00', array_reduce($services, fn ($c, $r) => bcadd($c, $r['received'], 2), '0.00'));
        // 30,000 and 20,000 paid on a bill of 30,000 + 20,000, and a bill of 10,000 paid in full.
        $this->assertSame('40000.00', collect($services)->firstWhere('service', 'Extraction')['received']);

        $byDay = $this->table($summary, 'by_day');
        $this->assertSame('50000.00', $byDay['total']['net']);
        $this->assertSame('50000.00', array_reduce($byDay['rows'], fn ($c, $r) => bcadd($c, $r['net'], 2), '0.00'));

        $this->assertSame('Dr Okello Peter', $this->table($summary, 'by_clinician')['rows'][0]['clinician']);

        $compare = collect($this->table($summary, 'comparison')['rows'])->keyBy('figure');
        $this->assertSame('5000.00', $compare['Received']['previous']);
        $this->assertSame('900.0', $compare['Net income']['change']);
    }

    // ── Expenses ─────────────────────────────────────────────────────────

    public function test_expenses_are_the_money_out_in_the_books(): void
    {
        $chart = app(ChartOfAccounts::class);
        $chart->ensure();
        $cashbook = app(CashbookService::class);
        $rent = LedgerAccount::query()->where('name', 'Rent')->firstOrFail();
        $power = LedgerAccount::query()->where('name', 'Electricity & water')->firstOrFail();
        $out = fn (Carbon $d, LedgerAccount $a, string $book, string $amount, string $memo) => $cashbook->moneyOut([
            'date' => $d->toDateString(), 'book_id' => $chart->key($book)->id, 'account_id' => $a->id,
            'amount' => $amount, 'memo' => $memo, 'party' => 'Landlord', 'reference' => 'CHQ-1',
        ], $this->admin->id);

        $out($this->day(1, 1), $rent, 'book.bank', '3500000', 'Rent, last month');
        $out($this->day(1), $rent, 'book.bank', '3500000', 'Rent');
        $out($this->day(4), $power, 'book.mobile_money', '450000', 'Power');
        $wrong = $out($this->day(6), $power, 'book.cash', '100000', 'Typed twice');
        app(LedgerService::class)->reverse($wrong, 'Typed twice', $this->admin->id);

        $p = $this->period();
        $report = app(ExpenseReport::class);
        $this->assertSame('3950000.00', $report->total($p));

        // The income statement's running costs for the same days say the same.
        $statement = app(FinancialStatements::class)->incomeStatement(Carbon::parse($p->from), Carbon::parse($p->to));
        $this->assertSame($statement['expenses']['total'], $report->running($p));

        // The reversed entry and its reversal both show, and cancel out.
        $rows = $this->rows($report, $p);
        $this->assertCount(4, $rows);
        $this->assertSame('3950000.00', array_reduce($rows, fn ($c, $r) => bcadd($c, (string) $r['amount'], 2), '0.00'));
        $this->assertSame('-100000.00', collect($rows)->last()['amount']);
        $this->assertSame('6100 · Rent', $rows[0]['category']);
        $this->assertSame('Landlord', $rows[0]['paid_to']);

        $summary = $report->summary($p);
        $this->assertSame('Expenditure report', $report->title());
        $cats = collect($this->table($summary, 'by_category')['rows'])->keyBy('category');
        $this->assertSame('3500000.00', $cats['6100 · Rent']['amount']);
        $this->assertSame('450000.00', $cats['6110 · Electricity & water']['amount']);
        $this->assertSame('3950000.00', array_reduce($this->table($summary, 'by_method')['rows'], fn ($c, $r) => bcadd($c, $r['amount'], 2), '0.00'));
        $this->assertSame('3950000.00', array_reduce($this->table($summary, 'by_week')['rows'], fn ($c, $r) => bcadd($c, $r['amount'], 2), '0.00'));
        $this->assertSame('3500000.00', collect($this->table($summary, 'comparison')['rows'])->first()['previous']);
    }

    // ── Accounts receivable ──────────────────────────────────────────────

    public function test_receivables_now_are_the_debtors_list(): void
    {
        $this->at(Carbon::now()->subDays(70));
        $this->bill(['40000.00']);
        $this->at(Carbon::now()->addDays(30));
        $this->pay($this->bill(['25000.00']), '5000.00');
        $this->at($this->day(10));
        $this->bill(['15000.00']);
        $paid = $this->bill(['8000.00']);
        $this->pay($paid, '8000.00');

        $p = $this->period();
        $report = app(ReceivablesReport::class);
        $position = $report->position($p->asAt(), true);
        $debtors = app(DebtorsService::class)->aging();

        $this->assertSame($debtors['total_outstanding'], $position['total']);
        $this->assertSame('75000.00', $position['total']);
        foreach (DebtorsService::BUCKETS as $band) {
            $this->assertSame($debtors['aging'][$band], $position['bands'][$band]['amount'], "band {$band}");
        }
        $this->assertSame(Invoice::query()->outstanding()->count(), $report->count($p));
        $rows = $this->rows($report, $p);
        $this->assertSame('61-90 days', $rows[0]['band']);
        $this->assertSame('20000.00', collect($rows)->firstWhere('total', '25000.00')['balance']);
    }

    public function test_receivables_for_a_past_month_are_worked_back_to_its_last_day(): void
    {
        $this->at($this->day(10, 1));
        $old = $this->bill(['30000.00']);
        $this->at($this->day(5));
        $this->pay($old, '30000.00');
        $this->at($this->day(10));

        $report = app(ReceivablesReport::class);
        $last = $this->period(1);
        $rows = $this->rows($report, $last);
        $this->assertCount(1, $rows);
        $this->assertSame('30000.00', $rows[0]['balance']);
        $this->assertSame('0.00', $rows[0]['paid']);
        $this->assertSame((int) Carbon::parse($old->created_at)->diffInDays($last->end()), $rows[0]['days']);

        // Paid this month: owed at its start, collected in it, owed at its end no longer.
        $summary = $report->summary($this->period());
        $collection = collect($this->table($summary, 'collection')['rows'])->pluck('amount', 'figure');
        $this->assertSame('30000.00', $collection['Owed at the start of the period']);
        $this->assertSame('30000.00', $collection['Collected in the period from what was owed at the start']);
        $this->assertSame('0.00', $collection['Owed at the end of the period']);
        $this->assertSame([], $this->rows($report, $this->period()));
    }

    // ── Patient records ──────────────────────────────────────────────────

    public function test_patient_records_are_the_register(): void
    {
        $returning = Patient::factory()->create(['hospital_id' => $this->h->id, 'sex' => 'female', 'dob' => $this->anchor->copy()->subYears(34)->subMonths(2)]);
        $this->at($this->day(12, 1));
        $this->bill(['10000.00'], $returning);
        $this->at($this->day(4));
        $invoice = $this->bill(['50000.00'], $returning, null, 'Dental caries');
        $this->pay($invoice, '20000.00');
        $this->at($this->day(6));
        Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $this->h->id, 'sex' => 'male'])->id]);
        $this->at($this->day(10));

        $p = $this->period();
        $report = app(PatientRecordsReport::class);
        $rows = $this->rows($report, $p);
        $this->assertCount(2, $rows);
        $this->assertSame('Returning', $rows[0]['attendance']);
        $this->assertSame(34, $rows[0]['age']);
        $this->assertSame('Extraction', $rows[0]['treatment']);
        $this->assertSame('Dental caries', $rows[0]['diagnosis']);
        $this->assertSame('50000.00', $rows[0]['billed']);
        $this->assertSame('20000.00', $rows[0]['paid']);
        $this->assertSame('30000.00', $rows[0]['balance']);
        $this->assertSame('Dr Okello Peter', $rows[0]['clinician']);
        $this->assertSame('New', $rows[1]['attendance']);

        $summary = $report->summary($p);
        $figures = collect($summary['figures'])->pluck('value', 'label');
        $this->assertSame(2, $figures['Patients seen']);
        $this->assertSame(1, $figures['New']);
        $this->assertSame(1, $figures['Returning']);
        $this->assertSame('Extraction', $this->table($summary, 'treatments')['rows'][0]['treatment']);
        $this->assertSame('Dental caries', $this->table($summary, 'diagnoses')['rows'][0]['diagnosis']);
        $this->assertSame(1, collect($this->table($summary, 'by_age')['rows'])->firstWhere('band', '18-39')['patients']);
        $this->assertSame(2, $this->table($summary, 'by_sex')['total']['patients']);
    }

    public function test_a_dental_clinic_says_dentist_and_a_hospital_says_doctor(): void
    {
        $this->assertSame('Doctor', MonthlyReport::clinician());

        $clinic = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic', 'packs' => [], 'modules' => null]]]);
        app(CurrentHospital::class)->set($clinic->id);
        $this->assertSame('Dentist', MonthlyReport::clinician());
        $this->assertContains('Dentist', array_column(app(IncomeReport::class)->columns(), 'label'));
    }

    // ── Who sees what ────────────────────────────────────────────────────

    public function test_one_hospital_never_sees_anothers_figures(): void
    {
        $this->incomeMonth();
        $other = Hospital::factory()->create();
        $this->at($this->day(4));
        $this->pay($this->bill(['99000.00'], null, $other), '99000.00');
        $this->at($this->day(10));

        app(CurrentHospital::class)->set($this->h->id);
        $p = $this->period();
        $this->assertSame('60000.00', app(IncomeReport::class)->headline($p)['received']);
        // Every bill here is settled; the other hospital's is not this one's to count either way.
        $this->assertSame(0, app(ReceivablesReport::class)->count($p));
        $this->assertNotContains('99000.00', array_column($this->rows(app(IncomeReport::class), $p), 'amount'));

        app(CurrentHospital::class)->set($other->id);
        $this->assertSame('99000.00', app(IncomeReport::class)->headline($this->period())['received']);
    }

    public function test_only_people_with_reports_access_open_them(): void
    {
        $this->get(route('admin.reports.monthly'))->assertOk()->assertSee('Monthly reports');
        $this->get(route('admin.reports.monthly.export', ['type' => 'income', 'view' => 'detail', 'format' => 'csv']))->assertOk();

        $this->actingAs($this->staff('accountant'));
        $this->get(route('admin.reports.monthly'))->assertOk();

        $nurse = $this->staff('nurse');
        $this->actingAs($nurse);
        $this->get(route('admin.reports.monthly'))->assertForbidden();
        $this->get(route('admin.reports.monthly.pdf', ['type' => 'income', 'view' => 'summary']))->assertForbidden();
        $this->get(route('admin.reports.monthly.export', ['type' => 'patients', 'view' => 'detail', 'format' => 'xlsx']))->assertForbidden();

        Sanctum::actingAs($nurse);
        $this->getJson(route('api.reports.monthly'))->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    // ── The page and its files ───────────────────────────────────────────

    public function test_the_page_shows_each_report_both_ways(): void
    {
        $this->incomeMonth();

        $page = Livewire::test(Monthly::class)->assertSee('Net income')->assertSee('By method');
        foreach (array_keys(MonthlyReports::TYPES) as $type) {
            $page->set('type', $type)->call('show', 'summary')->assertOk();
            $page->call('show', 'detail')->assertOk();
        }
        $page->set('type', 'income')->call('show', 'detail')->assertSee('RF-')->assertSee('Net income');

        // A custom range, and back to the month.
        $page->call('useCustom')->assertSet('custom', true)
            ->set('from', $this->day(5)->toDateString())->set('to', $this->day(6)->toDateString())
            ->assertSee($this->day(5)->format('j M Y').' to '.$this->day(6)->format('j M Y'))
            ->call('useMonth')->assertSet('custom', false);
    }

    public function test_every_export_opens(): void
    {
        $this->incomeMonth();
        $month = Carbon::now()->format('Y-m');

        foreach (array_keys(MonthlyReports::TYPES) as $type) {
            foreach (['detail', 'summary'] as $view) {
                $pdf = $this->get(route('admin.reports.monthly.pdf', ['type' => $type, 'view' => $view, 'month' => $month]));
                $pdf->assertOk();
                $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
                $this->assertStringStartsWith('inline', (string) $pdf->headers->get('content-disposition'));

                $xlsx = $this->get(route('admin.reports.monthly.export', ['type' => $type, 'view' => $view, 'format' => 'xlsx', 'month' => $month]));
                $xlsx->assertOk();
                $file = tempnam(sys_get_temp_dir(), 'mr').'.xlsx';
                file_put_contents($file, $xlsx->getContent());
                $sheet = SpreadsheetReader::read($file, 'report.xlsx');
                @unlink($file);
                $first = array_values($sheet)[0];
                $expect = $view === 'detail' ? app(MonthlyReports::class)->report($type)->columns()[0]['label'] : 'Figure';
                $this->assertSame($expect, $first[0], "{$type} {$view} xlsx");

                $csv = $this->get(route('admin.reports.monthly.export', ['type' => $type, 'view' => $view, 'format' => 'csv', 'month' => $month]));
                $csv->assertOk();
                $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
                $this->assertNotSame('', $csv->streamedContent());
            }
        }

        // The money in the workbook is a number a spreadsheet can add up.
        $xlsx = $this->get(route('admin.reports.monthly.export', ['type' => 'income', 'view' => 'detail', 'format' => 'xlsx', 'month' => $month]))->getContent();
        $zip = new \ZipArchive;
        $file = tempnam(sys_get_temp_dir(), 'mr');
        file_put_contents($file, $xlsx);
        $zip->open($file);
        $this->assertStringContainsString('<v>30000.00</v>', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($file);

        $csv = $this->get(route('admin.reports.monthly.export', ['type' => 'income', 'view' => 'detail', 'format' => 'csv', 'month' => $month]))->streamedContent();
        $this->assertStringContainsString('-10000.00', $csv);
        $this->assertStringContainsString('Net income', $csv);
    }

    // ── The app ──────────────────────────────────────────────────────────

    public function test_the_app_reads_the_same_report(): void
    {
        $this->incomeMonth();
        Sanctum::actingAs($this->admin);

        $this->getJson(route('api.reports.monthly', ['type' => 'income']))
            ->assertOk()
            ->assertJsonPath('data.view', 'summary')
            ->assertJsonPath('data.period.month', Carbon::now()->format('Y-m'))
            ->assertJsonPath('data.figures.2.label', 'Net income')
            ->assertJsonPath('data.figures.2.value', '50000.00')
            ->assertJsonPath('data.clinician_label', 'Doctor');

        $this->getJson(route('api.reports.monthly', ['type' => 'income', 'view' => 'detail', 'per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.columns.0.key', 'date');

        $this->getJson(route('api.reports.monthly', ['month' => '2026-13']))->assertStatus(422);
        $this->get(route('api.reports.monthly.pdf', ['type' => 'expenses', 'view' => 'summary']))->assertOk();
    }

    public function test_a_period_reads_months_and_ranges(): void
    {
        $p = ReportPeriod::resolve(null, '2026-03-20', '2026-03-01', $this->h);
        $this->assertSame(['2026-03-01', '2026-03-20'], [$p->from, $p->to]);
        $this->assertSame(['2026-02-09', '2026-02-28'], [$p->previous()->from, $p->previous()->to]);

        $long = ReportPeriod::resolve(null, '2024-01-01', '2026-01-01', $this->h);
        $this->assertSame('2024-12-31', $long->to);

        $m = ReportPeriod::resolve('2026-03', null, null, $this->h);
        $this->assertSame(['2026-03-01', '2026-03-31', 'March 2026'], [$m->from, $m->to, $m->label()]);
        $this->assertSame('2026-02', $m->previous()->month);
        $this->assertSame(Carbon::now('Africa/Kampala')->format('Y-m'), ReportPeriod::resolve('nonsense', null, null, $this->h)->month);
    }

    // ── The demo ─────────────────────────────────────────────────────────

    public function test_the_demo_fills_every_report(): void
    {
        $this->travelBack();
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);
        $hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        MonthlyReportsScenario::run($hospital);
        MonthlyReportsScenario::run($hospital);
        app(CurrentHospital::class)->set($hospital->id);

        $reports = app(MonthlyReports::class);
        $p = $reports->period(Carbon::now('Africa/Kampala')->format('Y-m'));
        foreach (array_keys(MonthlyReports::TYPES) as $type) {
            $this->assertGreaterThan(0, $reports->report($type)->count($p), "the demo's {$type} report is empty");
        }
        $this->assertGreaterThan(0, app(ExpenseReport::class)->count($reports->period(Carbon::now('Africa/Kampala')->subMonthNoOverflow()->format('Y-m'))));
    }

    // ── Review findings ──────────────────────────────────────────────────

    public function test_the_expenditure_book_has_all_money_paid_out_with_running_expenses_apart(): void
    {
        $chart = app(ChartOfAccounts::class);
        $chart->ensure();
        $cashbook = app(CashbookService::class);
        $named = fn (string $n) => LedgerAccount::query()->where('name', $n)->firstOrFail();
        $out = fn (int $day, LedgerAccount $a, string $amount) => $cashbook->moneyOut([
            'date' => $this->day($day)->toDateString(), 'book_id' => $chart->key('book.cash')->id, 'account_id' => $a->id,
            'amount' => $amount, 'memo' => 'Paid', 'party' => 'Someone',
        ], $this->admin->id);

        $out(2, $named('Rent'), '300000');
        $out(3, $named('Medical equipment'), '900000');
        $out(4, $chart->key('payable'), '250000');
        $out(5, $named("Owner's drawings"), '100000');

        // A delivery paid for on the spot, from the cash drawer.
        $settings = $this->h->settings ?? [];
        $settings['finance'] = ['stock_credit_account' => $chart->key('book.cash')->id];
        $this->h->forceFill(['settings' => $settings])->save();
        $this->at($this->day(6));
        app(StockService::class)->receive(StockItem::factory()->create(['hospital_id' => $this->h->id]), '10', '5000.00', $this->admin->id);
        $this->at($this->day(10));

        $p = $this->period();
        $report = app(ExpenseReport::class);
        $this->assertSame('1600000.00', $report->total($p));
        $this->assertSame('300000.00', $report->running($p));
        $statement = app(FinancialStatements::class)->incomeStatement(Carbon::parse($p->from), Carbon::parse($p->to));
        $this->assertSame($statement['expenses']['total'], $report->running($p));

        $kinds = collect($this->table($report->summary($p), 'by_kind')['rows'])->pluck('amount', 'kind');
        $this->assertSame('300000.00', $kinds['Running expense']);
        $this->assertSame('900000.00', $kinds['Equipment and other assets']);
        $this->assertSame('250000.00', $kinds['Paying a supplier']);
        $this->assertSame('100000.00', $kinds['Owner drawings']);
        $this->assertSame('50000.00', $kinds['Stock purchase']);
        $this->assertContains('Stock purchase', array_column($this->rows($report, $p), 'kind'));
    }

    public function test_clinical_notes_stay_with_people_who_may_read_them(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $this->at($this->day(4));
        Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'complaints' => 'Toothache, lower left', 'diagnosis' => 'Dental caries']);
        $this->at($this->day(10));
        $p = $this->period();

        $clinician = app(MonthlyReports::class)->report('patients', $this->doctor);
        $this->assertSame('Dental caries', $this->rows($clinician, $p)[0]['diagnosis']);
        $this->assertContains('diagnoses', array_column($clinician->summary($p)['tables'], 'key'));

        $accountant = $this->staff('accountant');
        $books = app(MonthlyReports::class)->report('patients', $accountant);
        $row = $this->rows($books, $p)[0];
        $this->assertSame('', $row['diagnosis']);
        $this->assertSame('', $row['complaint']);
        $this->assertNotContains('diagnoses', array_column($books->summary($p)['tables'], 'key'));

        $this->actingAs($accountant);
        foreach (['detail', 'summary'] as $view) {
            $csv = $this->get(route('admin.reports.monthly.export', ['type' => 'patients', 'view' => $view, 'format' => 'csv']))->streamedContent();
            $this->assertStringNotContainsString('Dental caries', $csv);
            $this->assertStringNotContainsString('Toothache', $csv);
        }
        Sanctum::actingAs($accountant);
        $this->assertStringNotContainsString('Dental caries', (string) $this->getJson(route('api.reports.monthly', ['type' => 'patients', 'view' => 'detail']))->assertOk()->getContent());
        $this->assertStringNotContainsString('Dental caries', (string) $this->getJson(route('api.reports.monthly', ['type' => 'patients']))->assertOk()->getContent());
    }

    public function test_a_page_reads_only_its_own_rows_and_the_count_is_kept(): void
    {
        $this->incomeMonth();
        $reports = app(MonthlyReports::class);
        $p = $this->period();

        foreach (array_keys(MonthlyReports::TYPES) as $type) {
            $all = $this->rows($reports->report($type), $p);
            $page = $reports->page($type, $p, 2, 1);
            $this->assertSame(array_slice($all, 1, 1), $page->items(), "page 2 of {$type}");
        }

        DB::enableQueryLog();
        $reports->page('income', $p, 1, 2);
        $counted = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'count('))->count();
        $this->assertSame(0, $counted, 'the count is read again on the next page');
    }

    public function test_no_hospital_no_report(): void
    {
        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $super->syncSpatieRole();
        $this->actingAs($super);
        app(CurrentHospital::class)->set(null);

        $this->get(route('admin.reports.monthly'))->assertNotFound();
        $this->get(route('admin.reports.monthly.export', ['type' => 'income', 'view' => 'summary', 'format' => 'csv']))->assertNotFound();
        $this->get(route('admin.reports.monthly.pdf', ['type' => 'income', 'view' => 'summary']))->assertNotFound();

        // And the menu offers it only inside a hospital.
        $this->assertStringContainsString("'route' => 'admin.reports.monthly', 'match' => ['admin.reports.monthly*'], 'can' => 'reports.view', 'tenant' => true", (string) file_get_contents(app_path('Support/Navigation.php')));
    }

    public function test_receivables_name_the_last_day_and_leave_old_debts_out_of_billing(): void
    {
        $this->at($this->day(10, 1));
        $this->bill(['30000.00']);
        $this->at($this->day(3));
        app(BillingService::class)->openingBalance(Patient::factory()->create(['hospital_id' => $this->h->id]), '70000.00', $this->admin->id);
        $this->at($this->day(10));

        $last = $this->period(1);
        $figures = collect(app(ReceivablesReport::class)->summary($last)['figures'])->keyBy('label');
        $this->assertSame('as at '.Carbon::parse($last->to)->format('j M Y'), $figures['Owed']['note']);

        $collection = collect($this->table(app(ReceivablesReport::class)->summary($this->period()), 'collection')['rows'])->pluck('amount', 'figure');
        $this->assertSame('0.00', $collection['Billed in the period']);
        $this->assertSame('100000.00', $collection['Owed at the end of the period']);
    }

    public function test_spreadsheets_keep_counts_and_percentages_as_what_they_are(): void
    {
        $this->incomeMonth();
        $xlsx = $this->get(route('admin.reports.monthly.export', ['type' => 'income', 'view' => 'summary', 'format' => 'xlsx']))->getContent();
        $file = tempnam(sys_get_temp_dir(), 'mr');
        file_put_contents($file, $xlsx);
        $zip = new \ZipArchive;
        $zip->open($file);
        $figures = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($file);

        $this->assertStringContainsString('+900.0%', $figures);
        $this->assertStringNotContainsString('<v>900.0</v>', $figures);

        $csv = $this->get(route('admin.reports.monthly.export', ['type' => 'income', 'view' => 'summary', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('900.0%', $csv);
    }

    public function test_a_failed_workbook_leaves_no_files_behind(): void
    {
        $before = count(glob(sys_get_temp_dir().'/td[rsx]*') ?: []);
        $rows = (function () {
            yield ['Name'];
            yield ['Amina'];
            throw new \RuntimeException('the database went away');
        })();

        try {
            XlsxWriter::make([['name' => 'A', 'rows' => $rows]]);
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('the database went away', $e->getMessage());
        }

        $this->assertSame($before, count(glob(sys_get_temp_dir().'/td[rsx]*') ?: []));
    }

    public function test_a_csv_cell_never_runs_as_a_formula(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => '=HYPERLINK("http://x")', 'last_name' => 'Okot']);
        $this->at($this->day(4));
        $this->pay($this->bill(['10000.00'], $patient), '10000.00');
        $this->at($this->day(10));

        $csv = $this->get(route('admin.reports.monthly.export', ['type' => 'income', 'view' => 'detail', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertSame('-10000.00', MonthlyReports::csvSafe('-10000.00'));
        $this->assertSame("'-cmd", MonthlyReports::csvSafe('-cmd'));
    }

    public function test_clearing_a_date_keeps_the_range(): void
    {
        $from = $this->day(5)->toDateString();
        $to = $this->day(6)->toDateString();

        Livewire::test(Monthly::class)->call('useCustom')
            ->set('from', $from)->set('to', $to)
            ->set('from', '')->assertSet('from', $from)->assertSet('custom', true)
            ->set('to', '')->assertSet('to', $to);
    }
}
