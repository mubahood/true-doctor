<?php

namespace App\Support\Insights\Rules;

use App\Services\Finance\BooksBackfill;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Records that exist but are not in the books yet: "Bring them up to date" on the finance overview. */
final class BooksCatchUp extends Rule
{
    public function key(): string
    {
        return 'books-catch-up';
    }

    protected function permission(): string
    {
        return 'finance.manage';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = array_sum(app(BooksBackfill::class)->pending());
        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'record').' not in the books',
            'Invoices, payments or stock movements that are not posted yet, so statements are short of them.',
            'admin.finance.index', 'Bring the books up to date', $n, 66,
        );
    }
}
