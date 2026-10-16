<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Models\LedgerAccount;
use App\Support\HospitalSettings;
use Illuminate\Validation\Rule;

/**
 * How the hospital's own work lands in its books (docs/finance.md): stored in
 * `settings.finance` as account ids. Shared by the web settings page and the
 * API so the choices offered and the validation are the same.
 */
class FinanceSettings
{
    /** @return array<string,mixed> */
    public function stored(): array
    {
        $hospital = app(HospitalSettings::class)->hospital();

        return is_array($hospital?->settings['finance'] ?? null) ? $hospital->settings['finance'] : [];
    }

    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    public function books()
    {
        return LedgerAccount::query()->books()->active()->ordered()->get();
    }

    /** Where a delivery can be owed: payables, or a book if it is paid on the spot. */
    /** @return \Illuminate\Support\Collection<int, LedgerAccount> */
    public function creditAccounts()
    {
        return LedgerAccount::query()->active()
            ->where(fn ($q) => $q->where('is_book', true)->orWhere('type', AccountType::Liability->value))
            ->ordered()->get();
    }

    /** What each falls back to when left blank. @return array<string,string> */
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

    /** @return array<string, array<int,mixed>> */
    public function rules(string $prefix = ''): array
    {
        $books = $this->books()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $credit = $this->creditAccounts()->pluck('id')->map(fn ($id) => (string) $id)->all();

        return [
            $prefix.'method_books' => ['array'],
            $prefix.'method_books.*' => ['nullable', Rule::in($books)],
            $prefix.'card_topup_book' => ['nullable', Rule::in($books)],
            $prefix.'insurer_deposit_book' => ['nullable', Rule::in($books)],
            $prefix.'stock_credit_account' => ['nullable', Rule::in($credit)],
        ];
    }

    /**
     * Save validated settings. A key not present keeps what is stored; a blank
     * one clears it back to the default.
     *
     * @param  array{method_books?:array<string,?string>,card_topup_book?:?string,insurer_deposit_book?:?string,stock_credit_account?:?string}  $data
     */
    public function save(array $data): void
    {
        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404);

        $stored = $this->stored();
        $id = fn ($v) => filled($v) ? (int) $v : null;

        $finance = $stored;
        if (array_key_exists('method_books', $data)) {
            $finance['method_books'] = array_map($id, array_intersect_key($data['method_books'], ChartOfAccounts::METHOD_BOOKS));
        }
        foreach (['card_topup_book', 'insurer_deposit_book', 'stock_credit_account'] as $key) {
            if (array_key_exists($key, $data)) {
                $finance[$key] = $id($data[$key]);
            }
        }

        $settings = $hospital->settings ?? [];
        $settings['finance'] = $finance;
        $hospital->settings = $settings;
        $hospital->save();

        app(ChartOfAccounts::class)->forget();
    }
}
