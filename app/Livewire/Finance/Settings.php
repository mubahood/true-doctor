<?php

namespace App\Livewire\Finance;

use App\Enums\PaymentMethod;
use App\Livewire\Concerns\KeepsBooks;
use App\Models\LedgerAccount;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinanceSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * How the hospital's own work lands in its books (docs/finance.md): which
 * book a cash, mobile-money or bank payment goes into, where card top-ups and
 * insurer deposits are kept, and whether stock deliveries are owed to the
 * supplier or paid on the spot.
 *
 * Stored in `settings.finance` as account ids. A setting left blank, or
 * pointing at an account since switched off, falls back to the standard
 * account — a finance setting is never what stops a patient being billed.
 * Changes apply from the next posting; nothing already posted moves.
 *
 * @property-read \Illuminate\Support\Collection<int, LedgerAccount> $books
 * @property-read \Illuminate\Support\Collection<int, LedgerAccount> $creditAccounts
 * @property-read array<string,string> $defaults
 */
#[Layout('layouts.admin')]
class Settings extends Component
{
    use KeepsBooks;

    /** @var array<string, string|null> method => book id */
    public array $methodBooks = [];

    public ?string $cardTopUpBook = null;

    public ?string $insurerDepositBook = null;

    public ?string $stockCreditAccount = null;

    public function mount(): void
    {
        $this->authorizeBooks('finance.manage');
        app(ChartOfAccounts::class)->ensure();

        $finance = $this->stored();
        foreach (array_keys(ChartOfAccounts::METHOD_BOOKS) as $method) {
            $this->methodBooks[$method] = isset($finance['method_books'][$method]) ? (string) $finance['method_books'][$method] : null;
        }
        $this->cardTopUpBook = isset($finance['card_topup_book']) ? (string) $finance['card_topup_book'] : null;
        $this->insurerDepositBook = isset($finance['insurer_deposit_book']) ? (string) $finance['insurer_deposit_book'] : null;
        $this->stockCreditAccount = isset($finance['stock_credit_account']) ? (string) $finance['stock_credit_account'] : null;
    }

    /** @return array<string,mixed> */
    private function stored(): array
    {
        return app(FinanceSettings::class)->stored();
    }

    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    #[Computed]
    public function books()
    {
        return app(FinanceSettings::class)->books();
    }

    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    #[Computed]
    public function creditAccounts()
    {
        return app(FinanceSettings::class)->creditAccounts();
    }

    /** What each falls back to when left blank. @return array<string,string> */
    #[Computed]
    public function defaults(): array
    {
        return app(FinanceSettings::class)->defaults();
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $this->validate($this->webRules());

        app(FinanceSettings::class)->save([
            'method_books' => $this->methodBooks,
            'card_topup_book' => $this->cardTopUpBook,
            'insurer_deposit_book' => $this->insurerDepositBook,
            'stock_credit_account' => $this->stockCreditAccount,
        ]);

        $this->dispatch('toast', message: 'Finance settings saved — they apply from the next posting.', type: 'success');
    }

    /** The shared rules, under this form's property names. @return array<string, array<int,mixed>> */
    private function webRules(): array
    {
        $rules = app(FinanceSettings::class)->rules();

        return [
            'methodBooks' => $rules['method_books'],
            'methodBooks.*' => $rules['method_books.*'],
            'cardTopUpBook' => $rules['card_topup_book'],
            'insurerDepositBook' => $rules['insurer_deposit_book'],
            'stockCreditAccount' => $rules['stock_credit_account'],
        ];
    }

    public function render()
    {
        $this->authorizeBooks('finance.manage');

        return view('livewire.finance.settings', [
            'methods' => collect(array_keys(ChartOfAccounts::METHOD_BOOKS))->mapWithKeys(fn ($m) => [$m => PaymentMethod::from($m)->label()])->all(),
        ])->title('Finance settings');
    }
}
