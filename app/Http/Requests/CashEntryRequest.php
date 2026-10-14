<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Money in, money out, a transfer between books, an accountant's journal
 * (docs/finance.md). Shape only — what makes an entry acceptable (a real book,
 * balanced lines, an open period) is CashbookService's and LedgerService's to
 * decide, so the web and anything after it get the same answer.
 */
class CashEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.record');
    }

    public function rules(): array
    {
        return self::rulesFor((string) $this->input('kind', 'in'));
    }

    /** @return array<string, array<int, mixed>> */
    public static function rulesFor(string $kind): array
    {
        $common = [
            'date' => ['required', 'date', 'before_or_equal:'.now()->addDays(31)->toDateString()],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'reference' => ['nullable', 'string', 'max:120'],
        ];

        return match ($kind) {
            'transfer' => $common + [
                'from_book_id' => ['required', 'integer'],
                'to_book_id' => ['required', 'integer', 'different:from_book_id'],
                'memo' => ['nullable', 'string', 'max:250'],
            ],
            default => $common + [
                'book_id' => ['required', 'integer'],
                'account_id' => ['required', 'integer'],
                'memo' => ['required', 'string', 'max:250'],
                'party' => ['nullable', 'string', 'max:120'],
            ],
        };
    }

    /** @return array<string, array<int, mixed>> */
    public static function journalRules(): array
    {
        return [
            'date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:250'],
            'reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:2', 'max:40'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'lines.*.memo' => ['nullable', 'string', 'max:250'],
        ];
    }
}
