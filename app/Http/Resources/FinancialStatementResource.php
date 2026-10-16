<?php

namespace App\Http\Resources;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the four statements as sections -> rows -> amounts, from the array
 * FinancialStatements returns (so the figures are the web's, not recomputed).
 * Wrap with `new FinancialStatementResource(['report' => ..., 'data' => ...])`.
 */
class FinancialStatementResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string,mixed> $d */
        $d = $this->resource['data'];

        return match ($this->resource['report']) {
            'balance' => $this->balance($d),
            'trial' => $this->trial($d),
            'cashflow' => $this->cashflow($d),
            default => $this->income($d),
        };
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function income(array $d): array
    {
        $sections = [];
        foreach (['revenue' => 'Income', 'discounts' => 'Discounts', 'cost_of_sales' => 'Cost of sales', 'expenses' => 'Expenses', 'other_income' => 'Other income'] as $key => $title) {
            $sections[] = $this->section($key, $title, $d[$key]['rows'], $d[$key]['total'], $d[$key]['compare'] ?? null, true);
        }

        $labels = ['net_revenue' => 'Net income', 'gross_profit' => 'Gross profit', 'operating_profit' => 'Operating profit', 'net_profit' => 'Net profit'];
        $totals = [];
        foreach ($labels as $key => $label) {
            $totals[] = ['key' => $key, 'label' => $label, 'amount' => $d['totals'][$key], 'compare' => $d['compare'][$key] ?? null];
        }

        return [
            'report' => 'income',
            'title' => 'Income statement',
            'from' => $d['from'], 'to' => $d['to'],
            'compare_from' => $d['compare_from'], 'compare_to' => $d['compare_to'],
            'sections' => $sections,
            'totals' => $totals,
            'margin' => $d['margin'],
        ];
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function balance(array $d): array
    {
        $sections = [];
        foreach (['current_assets' => 'Current assets', 'fixed_assets' => 'Fixed assets', 'current_liabilities' => 'Current liabilities', 'long_term_liabilities' => 'Long-term liabilities', 'equity' => 'Equity'] as $key => $title) {
            $sections[] = $this->section($key, $title, $d[$key]['rows'], $d[$key]['total']);
        }

        return [
            'report' => 'balance',
            'title' => 'Balance sheet',
            'as_of' => $d['as_of'],
            'year_start' => $d['year_start'],
            'sections' => $sections,
            'totals' => [
                ['key' => 'earlier_profit', 'label' => 'Profit kept from earlier years', 'amount' => $d['earlier_profit']],
                ['key' => 'year_profit', 'label' => 'Profit this year', 'amount' => $d['year_profit']],
                ['key' => 'equity_total', 'label' => 'Total equity', 'amount' => $d['equity_total']],
                ['key' => 'assets', 'label' => 'Total assets', 'amount' => $d['assets']],
                ['key' => 'liabilities', 'label' => 'Total liabilities', 'amount' => $d['liabilities']],
                ['key' => 'liabilities_and_equity', 'label' => 'Liabilities and equity', 'amount' => $d['liabilities_and_equity']],
            ],
            'balanced' => $d['balanced'],
        ];
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function trial(array $d): array
    {
        return [
            'report' => 'trial',
            'title' => 'Trial balance',
            'as_of' => $d['as_of'],
            'year_start' => $d['year_start'],
            'sections' => [[
                'key' => 'accounts',
                'title' => 'Accounts',
                'rows' => array_map(fn ($r) => $this->account($r['account']) + ['debit' => $r['debit'], 'credit' => $r['credit']], $d['rows']),
            ]],
            'totals' => [
                ['key' => 'debit', 'label' => 'Total debit', 'amount' => $d['debit']],
                ['key' => 'credit', 'label' => 'Total credit', 'amount' => $d['credit']],
            ],
            'balanced' => $d['balanced'],
        ];
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function cashflow(array $d): array
    {
        return [
            'report' => 'cashflow',
            'title' => 'Cash flow',
            'from' => $d['from'], 'to' => $d['to'],
            'sections' => [
                ['key' => 'inflows', 'title' => 'Money in', 'rows' => $d['inflows'], 'total' => $d['in']],
                ['key' => 'outflows', 'title' => 'Money out', 'rows' => $d['outflows'], 'total' => $d['out']],
                ['key' => 'books', 'title' => 'Books', 'rows' => array_map(fn ($b) => $this->account($b['account']) + ['opening' => $b['opening'], 'in' => $b['in'], 'out' => $b['out'], 'closing' => $b['closing']], $d['books'])],
            ],
            'totals' => [
                ['key' => 'opening', 'label' => 'Opening', 'amount' => $d['opening']],
                ['key' => 'in', 'label' => 'Money in', 'amount' => $d['in']],
                ['key' => 'out', 'label' => 'Money out', 'amount' => $d['out']],
                ['key' => 'closing', 'label' => 'Closing', 'amount' => $d['closing']],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function section(string $key, string $title, array $rows, string $total, ?string $compare = null, bool $withCompare = false): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'rows' => array_map(fn ($r) => $this->account($r['account']) + ['amount' => $r['amount']] + ($withCompare ? ['compare' => $r['compare'] ?? null] : []), $rows),
            'total' => $total,
        ] + ($withCompare ? ['compare_total' => $compare] : []);
    }

    /** @return array{account_id:int,code:string,name:string} */
    private function account(LedgerAccount $a): array
    {
        return ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name];
    }
}
