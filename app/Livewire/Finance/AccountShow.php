<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\KeepsBooks;
use App\Livewire\Concerns\PeeksJournalEntries;
use App\Models\LedgerAccount;
use App\Services\Finance\FinancialStatements;
use App\Support\ReportRange;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One account's ledger over a period (docs/finance.md): where it stood before,
 * every line in it with the balance after, and where it ended. For a book this
 * is the statement to set beside the bank's; for an expense, every time money
 * went on it.
 *
 * @property-read LedgerAccount $account
 * @property-read array<string,mixed> $statement
 * @property-read \App\Models\JournalEntry|null $peekedEntry
 */
#[Layout('layouts.admin')]
class AccountShow extends Component
{
    use KeepsBooks, PeeksJournalEntries;

    #[Locked]
    public int $accountId;

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    public function mount(int|string $account): void
    {
        $this->authorizeBooks('finance.view');

        // Tenant-scoped: another hospital's account is a 404.
        $this->accountId = LedgerAccount::query()->findOrFail($account)->id;

        if ($this->from === '' || $this->to === '') {
            [$this->from, $this->to] = ReportRange::forPreset('month');
        }
    }

    #[Computed]
    public function account(): LedgerAccount
    {
        return LedgerAccount::query()->findOrFail($this->accountId);
    }

    public function preset(string $key): void
    {
        [$this->from, $this->to] = ReportRange::forPreset($key);
        unset($this->statement);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['from', 'to'], true)) {
            unset($this->statement);
        }
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function statement(): array
    {
        [$from, $to] = ReportRange::read($this->from, $this->to);

        return app(FinancialStatements::class)->statement($this->account, Carbon::parse($from), Carbon::parse($to));
    }

    public function render()
    {
        $this->authorizeBooks('finance.view');

        return view('livewire.finance.account-show', [
            'presets' => ReportRange::presets(),
        ])->title($this->account->name);
    }
}
