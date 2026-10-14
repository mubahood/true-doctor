<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Models\LedgerAccount;
use App\Support\CurrentHospital;
use App\Support\Practice\Practice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The hospital's chart of accounts, and how the system finds its way round it
 * (docs/finance.md).
 *
 * Every hospital gets the same standard chart the first time anything asks for
 * an account, plus whatever its kind of practice adds — a dental practice gets
 * "Dental income" and "Dental laboratory fees". After that the chart is the
 * hospital's: accounts can be renamed, renumbered, added and switched off.
 * The ones with a `system_key` cannot be switched off, because the system
 * posts to them by that key.
 *
 * Money "books" are the accounts money actually sits in. Which book a cash
 * payment, a mobile-money payment or a card top-up lands in is the hospital's
 * own setting (`settings.finance`), defaulting to the obvious one.
 */
class ChartOfAccounts
{
    /**
     * [code, name, type, subtype, system_key, is_book].
     *
     * Numbered the way most small-practice accountants in the region number
     * them, with gaps left so a hospital can slot its own accounts in.
     */
    public const STANDARD = [
        // Assets
        ['1000', 'Cash at hand', 'asset', 'cash', 'book.cash', true],
        ['1010', 'Petty cash', 'asset', 'cash', 'book.petty_cash', true],
        ['1020', 'Bank account', 'asset', 'bank', 'book.bank', true],
        ['1030', 'Mobile money', 'asset', 'mobile_money', 'book.mobile_money', true],
        ['1040', 'Online payments clearing', 'asset', 'bank', 'book.online', true],
        ['1100', 'Patient receivables', 'asset', 'receivable', 'receivable', false],
        ['1110', 'Insurance receivables', 'asset', 'receivable', 'receivable.insurance', false],
        ['1200', 'Inventory — drugs & supplies', 'asset', 'inventory', 'inventory', false],
        ['1300', 'Prepaid expenses', 'asset', 'current_asset', null, false],
        ['1500', 'Medical equipment', 'asset', 'fixed_asset', null, false],
        ['1510', 'Furniture & fittings', 'asset', 'fixed_asset', null, false],
        ['1520', 'Motor vehicles', 'asset', 'fixed_asset', null, false],
        ['1590', 'Accumulated depreciation', 'asset', 'contra_asset', null, false],
        ['1900', 'Suspense', 'asset', 'suspense', 'suspense', false],
        // Liabilities
        ['2000', 'Accounts payable — suppliers', 'liability', 'payable', 'payable', false],
        ['2100', 'Patient card deposits', 'liability', 'deposits', 'card_deposits', false],
        ['2110', 'Insurer deposits', 'liability', 'deposits', 'insurer_floats', false],
        ['2200', 'Tax payable', 'liability', 'tax', 'tax.output', false],
        ['2300', 'Salaries payable', 'liability', 'payable', null, false],
        ['2310', 'PAYE & social security payable', 'liability', 'tax', null, false],
        ['2400', 'Loans', 'liability', 'loan', null, false],
        // Equity
        ['3000', 'Owner\'s capital', 'equity', 'capital', 'capital', false],
        ['3100', 'Opening balance equity', 'equity', 'opening', 'opening_equity', false],
        ['3200', 'Retained earnings', 'equity', 'retained', 'retained_earnings', false],
        ['3300', 'Owner\'s drawings', 'equity', 'drawings', null, false],
        // Income
        ['4000', 'Consultation fees', 'income', 'operating_income', 'revenue.consultation', false],
        ['4010', 'Laboratory income', 'income', 'operating_income', 'revenue.laboratory', false],
        ['4020', 'Imaging income', 'income', 'operating_income', 'revenue.imaging', false],
        ['4030', 'Pharmacy sales', 'income', 'operating_income', 'revenue.pharmacy', false],
        ['4040', 'Procedures & theatre income', 'income', 'operating_income', 'revenue.procedures', false],
        ['4050', 'Inpatient & ward income', 'income', 'operating_income', 'revenue.inpatient', false],
        ['4099', 'Other patient income', 'income', 'operating_income', 'revenue.other', false],
        ['4500', 'Grants & donations', 'income', 'other_income', null, false],
        ['4600', 'Other income', 'income', 'other_income', null, false],
        ['4610', 'Stock count gains', 'income', 'other_income', 'stock_gains', false],
        ['4900', 'Discounts allowed', 'income', 'contra_income', 'discounts', false],
        // Expenses
        ['5000', 'Cost of drugs & supplies sold', 'expense', 'cost_of_sales', 'cogs', false],
        ['5010', 'Stock losses & expiries', 'expense', 'cost_of_sales', 'stock_losses', false],
        ['6000', 'Salaries & wages', 'expense', 'operating_expense', null, false],
        ['6010', 'Allowances & locum fees', 'expense', 'operating_expense', null, false],
        ['6100', 'Rent', 'expense', 'operating_expense', null, false],
        ['6110', 'Electricity & water', 'expense', 'operating_expense', null, false],
        ['6120', 'Internet & telephone', 'expense', 'operating_expense', null, false],
        ['6200', 'Medical supplies (not stocked)', 'expense', 'operating_expense', null, false],
        ['6300', 'Repairs & maintenance', 'expense', 'operating_expense', null, false],
        ['6310', 'Cleaning & sanitation', 'expense', 'operating_expense', null, false],
        ['6320', 'Fuel & transport', 'expense', 'operating_expense', null, false],
        ['6330', 'Medical waste disposal', 'expense', 'operating_expense', null, false],
        ['6400', 'Licences & regulatory fees', 'expense', 'operating_expense', null, false],
        ['6410', 'Professional fees', 'expense', 'operating_expense', null, false],
        ['6420', 'Bank & mobile-money charges', 'expense', 'operating_expense', null, false],
        ['6500', 'Marketing', 'expense', 'operating_expense', null, false],
        ['6600', 'Insurance premiums', 'expense', 'operating_expense', null, false],
        ['6700', 'Depreciation', 'expense', 'operating_expense', null, false],
        ['6900', 'Miscellaneous expenses', 'expense', 'operating_expense', null, false],
    ];

    /** Where each way of paying lands unless the hospital says otherwise. */
    public const METHOD_BOOKS = [
        'cash' => 'book.cash',
        'mobile_money' => 'book.mobile_money',
        'bank' => 'book.bank',
        'flutterwave' => 'book.online',
    ];

    /** @var array<int, array<string, LedgerAccount>> hospital id => system key => account */
    private array $memo = [];

    /**
     * Make sure the hospital in context has its chart: the standard accounts
     * the first time, and from then on any system account or practice account
     * it is missing. Never touches one that is already there.
     */
    public function ensure(): void
    {
        $hospitalId = $this->hospitalId();

        if (isset($this->memo[$hospitalId])) {
            return;
        }

        DB::transaction(function () {
            $existing = LedgerAccount::query()->get(['id', 'code', 'name', 'system_key']);
            $codes = $existing->pluck('code')->map(fn ($c) => (string) $c)->all();
            $names = $existing->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();
            $keys = $existing->pluck('system_key')->filter()->all();
            $fresh = $existing->isEmpty();

            $rows = array_map(fn (array $r) => [...$r, false], self::STANDARD);
            foreach (Practice::current()->template()->accounts() as [$code, $name, $type, $subtype, $key]) {
                $rows[] = [$code, $name, $type, $subtype, $key, false, true];
            }

            foreach ($rows as [$code, $name, $type, $subtype, $key, $isBook, $fromPractice]) {
                // A brand-new chart gets everything. An existing one gets the
                // accounts the system cannot work without, and its practice's
                // own accounts by name — but never a standard account the
                // hospital has chosen to delete.
                $needed = $fresh
                    || ($key !== null && ! in_array($key, $keys, true))
                    || ($fromPractice && $key === null && ! in_array(mb_strtolower($name), $names, true));
                if (! $needed) {
                    continue;
                }

                LedgerAccount::query()->forceCreate([
                    'hospital_id' => $this->hospitalId(),
                    'code' => $this->freeCode($code, $codes),
                    'name' => $name,
                    'type' => $type,
                    'subtype' => $subtype,
                    'system_key' => $key,
                    'is_book' => $isBook,
                    'is_active' => true,
                ]);

                $codes[] = $code;
                $names[] = mb_strtolower($name);
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        });

        $this->memo[$hospitalId] = LedgerAccount::query()
            ->whereNotNull('system_key')
            ->get()
            ->keyBy('system_key')
            ->all();
    }

    /** The account the system posts to by this key. */
    public function key(string $systemKey): LedgerAccount
    {
        $this->ensure();

        $account = $this->memo[$this->hospitalId()][$systemKey] ?? null;

        if ($account === null) {
            throw new RuntimeException("The chart of accounts has no account for “{$systemKey}”.");
        }

        return $account;
    }

    public function has(string $systemKey): bool
    {
        $this->ensure();

        return isset($this->memo[$this->hospitalId()][$systemKey]);
    }

    /**
     * The book a payment by this method lands in.
     *
     * A prepaid card and an insurance settlement are not money arriving — the
     * first spends a deposit already held, the second is a promise from an
     * insurer — so they land in the accounts that say so.
     */
    public function forMethod(PaymentMethod $method): LedgerAccount
    {
        return match ($method) {
            PaymentMethod::Card => $this->key('card_deposits'),
            PaymentMethod::Insurance => $this->key('receivable.insurance'),
            default => $this->setting('method_books.'.$method->value)
                ?? $this->key(self::METHOD_BOOKS[$method->value]),
        };
    }

    /** Where money taken for a prepaid card top-up is kept. */
    public function cardTopUpBook(): LedgerAccount
    {
        return $this->setting('card_topup_book') ?? $this->key('book.cash');
    }

    /** Where an insurer's deposit arrives — insurers pay by transfer. */
    public function insurerDepositBook(): LedgerAccount
    {
        return $this->setting('insurer_deposit_book') ?? $this->key('book.bank');
    }

    /**
     * What a delivery of stock is owed against. A supplier usually invoices
     * and is paid later, so by default it is a payable; a hospital that pays
     * cash on delivery names its cash book instead.
     */
    public function stockCreditAccount(): LedgerAccount
    {
        return $this->setting('stock_credit_account') ?? $this->key('payable');
    }

    /** Forget what was read, so the next call sees a change made since. */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** An account named in the hospital's finance settings, if it is still a usable one. */
    private function setting(string $path): ?LedgerAccount
    {
        $hospital = \App\Models\Hospital::find($this->hospitalId());
        $finance = is_array($hospital?->settings['finance'] ?? null) ? $hospital->settings['finance'] : [];
        $id = data_get($finance, $path);

        if (! is_numeric($id)) {
            return null;
        }

        return LedgerAccount::query()->active()->find((int) $id);
    }

    /** The code asked for, or the next free one after it. @param list<string> $taken */
    private function freeCode(string $code, array $taken): string
    {
        $candidate = $code;

        while (in_array($candidate, $taken, true)) {
            $candidate = (string) ((int) $candidate + 1);
        }

        return $candidate;
    }

    private function hospitalId(): int
    {
        $id = app(CurrentHospital::class)->id();

        if ($id === null) {
            throw new RuntimeException('The books belong to a hospital, and none is in context.');
        }

        return $id;
    }

    /** @return array<string, AccountType> */
    public static function types(): array
    {
        return array_reduce(AccountType::cases(), fn ($c, $t) => $c + [$t->value => $t], []);
    }
}
