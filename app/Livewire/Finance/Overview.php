<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\KeepsBooks;
use App\Livewire\Concerns\PeeksJournalEntries;
use App\Livewire\Concerns\RecordsMoney;
use App\Services\Finance\BooksBackfill;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use App\Support\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The finance front page (docs/finance.md): where the money is right now, what
 * the period earned and spent, who owes the hospital and whom it owes, and
 * the three things people come here to do — money in, money out, move money
 * between books.
 *
 * @property-read array<string,mixed> $figures
 * @property-read array<string,int> $pending
 * @property-read \Illuminate\Support\Collection<int, \App\Models\LedgerAccount> $moneyBooks
 * @property-read array<string, array<int,string>> $moneyCategories
 * @property-read \App\Models\JournalEntry|null $peekedEntry
 *
 * It also says whether the books are complete. Everything the hospital did
 * before it had books (or anything that ever slipped past live posting) shows
 * as a count, with one button to bring it in.
 */
#[Layout('layouts.admin')]
class Overview extends Component
{
    use KeepsBooks, PeeksJournalEntries, RecordsMoney;

    #[Url(history: true, except: 'month')]
    public string $range = 'month';

    public function mount(): void
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();

        if (! array_key_exists($this->range, ReportRange::presets())) {
            $this->range = 'month';
        }
    }

    public function updatedRange(): void
    {
        if (! array_key_exists($this->range, ReportRange::presets())) {
            $this->range = 'month';
        }
        unset($this->figures);
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function figures(): array
    {
        [$from, $to] = ReportRange::forPreset($this->range);

        return app(FinancialStatements::class)->overview(Carbon::parse($from), Carbon::parse($to));
    }

    /** @return array<string,int> */
    #[Computed]
    public function pending(): array
    {
        return app(BooksBackfill::class)->pending();
    }

    /** Post everything the books are missing. */
    public function catchUp(BooksBackfill $backfill): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $posted = array_sum($backfill->run());

        unset($this->pending, $this->figures);
        $this->dispatch('toast', message: $posted > 0 ? $posted.' records brought into the books.' : 'The books were already up to date.', type: 'success');
    }

    protected function afterMoneyRecorded(): void
    {
        unset($this->figures);
    }

    protected function afterEntryReversed(): void
    {
        unset($this->figures);
    }

    public function render()
    {
        $this->authorizeBooks('finance.view');

        return view('livewire.finance.overview', [
            'presets' => ReportRange::presets(),
        ])->title('Finance overview');
    }
}
