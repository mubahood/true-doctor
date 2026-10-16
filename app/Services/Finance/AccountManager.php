<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Exceptions\AccountRuleException;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Service;

/**
 * Changes to the chart of accounts (docs/finance.md), shared by the web chart
 * and the API so the rules about system accounts and used accounts live once.
 */
class AccountManager
{
    /** @return array<int,int> account id => journal lines */
    public function usage(): array
    {
        return JournalLine::query()->groupBy('ledger_account_id')
            ->selectRaw('ledger_account_id, COUNT(*) as n')->pluck('n', 'ledger_account_id')->map(fn ($n) => (int) $n)->all();
    }

    /** The next free code in the type's range, after the last one used. */
    public function nextCode(AccountType $type): string
    {
        $codes = LedgerAccount::query()->where('type', $type->value)->pluck('code')
            ->filter(fn ($c) => ctype_digit((string) $c))->map(fn ($c) => (int) $c)->sort()->values();

        $next = $codes->isEmpty() ? (int) ($type->codePrefix().'000') : $codes->last() + 10;
        while (LedgerAccount::query()->where('code', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    /**
     * Add an account, or save changes to one.
     *
     * @param  array{code:string,name:string,type:string,is_book?:bool,description?:?string,is_active?:bool}  $data
     *
     * @throws AccountRuleException
     */
    public function save(?LedgerAccount $account, array $data): LedgerAccount
    {
        // Only an asset can hold money.
        $data['is_book'] = (bool) ($data['is_book'] ?? false) && $data['type'] === AccountType::Asset->value;
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        if ($account !== null) {
            $used = $this->isUsed($account);

            if ($account->isSystem() && ! $data['is_active']) {
                throw new AccountRuleException('The system posts to this account, so it stays on.', 'is_active');
            }
            if ($account->isSystem() && $data['type'] !== $account->type->value) {
                throw new AccountRuleException('The system posts to this account as '.strtolower($account->type->label()).'; its kind cannot change.', 'type');
            }
            if ($used && $data['type'] !== $account->type->value) {
                throw new AccountRuleException('This account already has entries — moving it to another kind would rewrite past statements. Make a new account instead.', 'type');
            }

            $account->update($data);
        } else {
            $account = LedgerAccount::create($data + ['subtype' => $this->subtypeFor(AccountType::from($data['type']), $data['is_book'])]);
        }

        app(ChartOfAccounts::class)->forget();

        return $account;
    }

    /** Switch an account off or back on. A system account stays on. @throws AccountRuleException */
    public function toggleActive(LedgerAccount $account): LedgerAccount
    {
        if ($account->isSystem() && $account->is_active) {
            throw new AccountRuleException('The system posts to “'.$account->name.'”, so it stays on.', 'is_active');
        }

        $account->update(['is_active' => ! $account->is_active]);
        app(ChartOfAccounts::class)->forget();

        return $account;
    }

    /** Delete an account nothing has used. @throws AccountRuleException */
    public function delete(LedgerAccount $account): void
    {
        if ($account->isSystem() || $this->isUsed($account) || Service::query()->where('ledger_account_id', $account->id)->exists()) {
            throw new AccountRuleException('This account is in use — switch it off instead, and its history stays.');
        }

        $account->delete();
    }

    private function isUsed(LedgerAccount $account): bool
    {
        return JournalLine::query()->where('ledger_account_id', $account->id)->exists();
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
}
