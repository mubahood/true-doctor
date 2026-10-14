<?php

namespace App\Livewire\Finance;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Livewire\Concerns\KeepsBooks;
use App\Models\LedgerAccount;
use App\Services\Finance\ChartOfAccounts;
use App\Support\HospitalSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
        $hospital = app(HospitalSettings::class)->hospital();

        return is_array($hospital?->settings['finance'] ?? null) ? $hospital->settings['finance'] : [];
    }

    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    #[Computed]
    public function books()
    {
        return LedgerAccount::query()->books()->active()->ordered()->get();
    }

    /** Where a delivery can be owed: payables, or a book if it is paid on the spot. */
    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    #[Computed]
    public function creditAccounts()
    {
        return LedgerAccount::query()->active()
            ->where(fn ($q) => $q->where('is_book', true)->orWhere('type', AccountType::Liability->value))
            ->ordered()->get();
    }

    /** What each falls back to when left blank. @return array<string,string> */
    #[Computed]
    public function defaults(): array
    {
        $chart = app(ChartOfAccounts::class);
        $out = [];
        foreach (ChartOfAccounts::METHOD_BOOKS as $method => $key) {
            $out['method.'.$method] = $chart->has($key) ? $chart->key($key)->name : '—';
        }
        $out['card'] = $chart->key('book.cash')->name;
        $out['insurer'] = $chart->key('book.bank')->name;
        $out['stock'] = $chart->key('payable')->name;

        return $out;
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $books = $this->books->pluck('id')->map(fn ($id) => (string) $id)->all();
        $credit = $this->creditAccounts->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->validate([
            'methodBooks' => ['array'],
            'methodBooks.*' => ['nullable', Rule::in($books)],
            'cardTopUpBook' => ['nullable', Rule::in($books)],
            'insurerDepositBook' => ['nullable', Rule::in($books)],
            'stockCreditAccount' => ['nullable', Rule::in($credit)],
        ]);

        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404);

        $settings = $hospital->settings ?? [];
        $settings['finance'] = array_merge($this->stored(), [
            'method_books' => array_map(fn ($v) => filled($v) ? (int) $v : null, array_intersect_key($this->methodBooks, ChartOfAccounts::METHOD_BOOKS)),
            'card_topup_book' => filled($this->cardTopUpBook) ? (int) $this->cardTopUpBook : null,
            'insurer_deposit_book' => filled($this->insurerDepositBook) ? (int) $this->insurerDepositBook : null,
            'stock_credit_account' => filled($this->stockCreditAccount) ? (int) $this->stockCreditAccount : null,
        ]);
        $hospital->settings = $settings;
        $hospital->save();

        $this->dispatch('toast', message: 'Finance settings saved — they apply from the next posting.', type: 'success');
    }

    public function render()
    {
        $this->authorizeBooks('finance.manage');

        return view('livewire.finance.settings', [
            'methods' => collect(array_keys(ChartOfAccounts::METHOD_BOOKS))->mapWithKeys(fn ($m) => [$m => PaymentMethod::from($m)->label()])->all(),
        ])->title('Finance settings');
    }
}
