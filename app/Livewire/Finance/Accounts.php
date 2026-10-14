<?php

namespace App\Livewire\Finance;

use App\Enums\AccountType;
use App\Http\Requests\LedgerAccountRequest;
use App\Livewire\Concerns\KeepsBooks;
use App\Models\LedgerAccount;
use App\Services\Finance\ChartOfAccounts;
use App\Services\Finance\FinancialStatements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The chart of accounts (docs/finance.md): every account, grouped the way the
 * statements group them, with what it stands at today.
 *
 * The hospital's own categories — "Generator fuel", "Locum doctors", a second
 * bank account — are added here. An account the system posts to (it carries
 * a system key) can be renamed and renumbered but not switched off or
 * deleted. Any account with entries in it can be switched off but not
 * deleted, because its history is part of the books.
 *
 * @property-read array<int,string> $balances
 * @property-read array<int,int> $usage
 */
#[Layout('layouts.admin')]
class Accounts extends Component
{
    use KeepsBooks;

    #[Url(as: 'type', history: true, except: '')]
    public string $filterType = '';

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'off', history: true, except: false)]
    public bool $showInactive = false;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $type = 'expense';

    public bool $is_book = false;

    public ?string $description = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorizeBooks('finance.view');
        app(ChartOfAccounts::class)->ensure();
    }

    /** @return array<int,string> */
    #[Computed]
    public function balances(): array
    {
        return app(FinancialStatements::class)->balances(Carbon::now());
    }

    /** @return array<int,int> account id => lines */
    #[Computed]
    public function usage(): array
    {
        return \App\Models\JournalLine::query()->groupBy('ledger_account_id')
            ->selectRaw('ledger_account_id, COUNT(*) as n')->pluck('n', 'ledger_account_id')->map(fn ($n) => (int) $n)->all();
    }

    public function create(string $type = 'expense'): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $this->reset(['editingId', 'code', 'name', 'is_book', 'description']);
        $this->type = (AccountType::tryFrom($type) ?? AccountType::Expense)->value;
        $this->is_active = true;
        $this->code = $this->nextCode(AccountType::from($this->type));
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $account = LedgerAccount::query()->findOrFail($id);
        $this->editingId = $account->id;
        $this->code = $account->code;
        $this->name = $account->name;
        $this->type = $account->type->value;
        $this->is_book = $account->is_book;
        $this->description = $account->description;
        $this->is_active = $account->is_active;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function updatedType(): void
    {
        if ($this->editingId === null) {
            $this->code = $this->nextCode(AccountType::from($this->type));
            $this->is_book = $this->is_book && $this->type === AccountType::Asset->value;
        }
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $data = $this->validate(LedgerAccountRequest::rulesFor($this->editingId));
        $account = $this->editingId !== null ? LedgerAccount::query()->findOrFail($this->editingId) : null;

        // Only an asset can hold money.
        $data['is_book'] = (bool) ($data['is_book'] ?? false) && $data['type'] === AccountType::Asset->value;

        if ($account !== null) {
            $used = ($this->usage[$account->id] ?? 0) > 0;

            if ($account->isSystem() && ! $data['is_active']) {
                $this->addError('is_active', 'The system posts to this account, so it stays on.');

                return;
            }
            if ($account->isSystem() && $data['type'] !== $account->type->value) {
                $this->addError('type', 'The system posts to this account as '.strtolower($account->type->label()).'; its kind cannot change.');

                return;
            }
            if ($used && $data['type'] !== $account->type->value) {
                $this->addError('type', 'This account already has entries — moving it to another kind would rewrite past statements. Make a new account instead.');

                return;
            }

            $account->update($data);
        } else {
            LedgerAccount::create($data + ['subtype' => $this->subtypeFor(AccountType::from($data['type']), $data['is_book'])]);
        }

        $this->showForm = false;
        unset($this->balances, $this->usage);
        app(ChartOfAccounts::class)->forget();
        $this->dispatch('toast', message: $account ? 'Account saved.' : 'Account added.', type: 'success');
    }

    public function toggleActive(int $id): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $account = LedgerAccount::query()->findOrFail($id);
        if ($account->isSystem() && $account->is_active) {
            $this->dispatch('toast', message: 'The system posts to “'.$account->name.'”, so it stays on.', type: 'error');

            return;
        }

        $account->update(['is_active' => ! $account->is_active]);
        $this->dispatch('toast', message: $account->is_active ? 'Switched back on.' : 'Switched off — it keeps its history.', type: 'success');
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()?->can('finance.manage'), 403);

        $account = LedgerAccount::query()->findOrFail($id);

        if ($account->isSystem() || $account->lines()->exists() || \App\Models\Service::query()->where('ledger_account_id', $account->id)->exists()) {
            $this->dispatch('toast', message: 'This account is in use — switch it off instead, and its history stays.', type: 'error');

            return;
        }

        $account->delete();
        unset($this->usage);
        $this->dispatch('toast', message: 'Account deleted.', type: 'success');
    }

    /** The next free code in the type's range, after the last one used. */
    private function nextCode(AccountType $type): string
    {
        $codes = LedgerAccount::query()->where('type', $type->value)->pluck('code')
            ->filter(fn ($c) => ctype_digit((string) $c))->map(fn ($c) => (int) $c)->sort()->values();

        $next = $codes->isEmpty() ? (int) ($type->codePrefix().'000') : $codes->last() + 10;
        while (LedgerAccount::query()->where('code', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    private function subtypeFor(AccountType $type, bool $isBook): string
    {
        return match ($type) {
            AccountType::Asset => $isBook ? 'cash' : 'current_asset',
            AccountType::Liability => 'payable',
            AccountType::Equity => 'capital',
            AccountType::Income => 'operating_income',
            AccountType::Expense => 'operating_expense',
        };
    }

    public function render()
    {
        $this->authorizeBooks('finance.view');

        $accounts = LedgerAccount::query()
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->when(AccountType::tryFrom($this->filterType), fn ($q) => $q->where('type', $this->filterType))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($this->search).'%')->orWhere('code', 'like', trim($this->search).'%')))
            ->ordered()->get();

        return view('livewire.finance.accounts', [
            'groups' => collect(AccountType::cases())
                ->mapWithKeys(fn (AccountType $t) => [$t->value => ['type' => $t, 'accounts' => $accounts->where('type', $t)->values()]])
                ->filter(fn ($g) => $g['accounts']->isNotEmpty()),
            'types' => AccountType::options(),
        ])->title('Chart of accounts');
    }
}
