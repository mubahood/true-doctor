<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\AccountType;
use App\Enums\JournalKind;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Patient;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Finance\CashbookService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Reports\MonthlyReports as Reports;
use App\Support\CurrentHospital;
use Illuminate\Support\Carbon;

/**
 * Monthly reports in the demo hospital (docs/reports.md).
 *
 * The demo's payments, refunds and visits already fill the income and
 * patient records reports. Two gaps are filled here, so every report has
 * lines whichever day the demo is opened:
 *
 *   - the expense report reads the money out recorded in Finance, and the
 *     demo's monthly bills fall on fixed days, so early in a month there may
 *     be none yet: a month with no money out gets one purchase of supplies;
 *   - a clinic moving off spreadsheets brings its receivables list with it:
 *     when nobody owes anything, two patients get the balance they owed in
 *     the old books (BillingService::openingBalance), which is what the
 *     accounts receivable report then lists.
 *
 * Idempotent: a month that already has money out, and a hospital that is
 * already owed money, are left alone.
 */
class MonthlyReports
{
    public static function run(Hospital $hospital): void
    {
        app(CurrentHospital::class)->within($hospital->id, function () use ($hospital) {
            $by = User::query()->where('hospital_id', $hospital->id)->whereIn('role', ['accountant', 'hospital_admin'])->orderBy('id')->value('id');
            $chart = app(ChartOfAccounts::class);
            $chart->ensure();

            // A running cost, never cost of sales: that is posted by the stock ledger.
            $running = fn () => LedgerAccount::query()->where('type', AccountType::Expense->value)->where('is_book', false)
                ->where(fn ($q) => $q->whereNull('subtype')->orWhere('subtype', '!=', 'cost_of_sales'));
            $category = $running()->where(fn ($q) => $q->where('name', 'like', '%suppl%')->orWhere('name', 'like', '%material%'))->orderBy('code')->first()
                ?? $running()->whereNull('system_key')->orderBy('code')->first();
            if ($category === null) {
                return;
            }

            if (Invoice::query()->outstanding()->doesntExist()) {
                $owed = ['120000.00', '45000.00'];
                foreach (Patient::query()->orderBy('id')->limit(count($owed))->get() as $i => $patient) {
                    try {
                        app(BillingService::class)->openingBalance($patient, $owed[$i], $by === null ? null : (int) $by, 'Balance brought forward from the old receivables list');
                    } catch (\RuntimeException|\App\Exceptions\ClosedPeriodException) {
                        // A closed financial year: nothing to bring forward into it.
                    }
                }
            }

            $today = Carbon::today();
            foreach ([$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->startOfMonth()] as $month) {
                $period = app(Reports::class)->period($month->format('Y-m'));
                $has = JournalEntry::query()->where('kind', JournalKind::Expense->value)
                    ->where('date', '>=', $period->from)->where('date', '<=', $period->to)->exists();
                if ($has) {
                    continue;
                }

                try {
                    app(CashbookService::class)->moneyOut([
                        'date' => $month->copy()->addDays(2)->min($today)->toDateString(),
                        'book_id' => $chart->key('book.cash')->id,
                        'account_id' => $category->id,
                        'amount' => '185000.00',
                        'memo' => 'Gloves, masks and cotton rolls',
                        'party' => 'Medisave Supplies',
                        'reference' => 'DEMO-'.$month->format('Ym'),
                    ], $by === null ? null : (int) $by);
                } catch (\DomainException|\App\Exceptions\ClosedPeriodException) {
                    // A closed financial year: leave that month as it is.
                }
            }
        });
    }
}
