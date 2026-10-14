<?php

namespace App\Console\Commands;

use App\Models\Hospital;
use App\Services\Finance\BooksBackfill;
use App\Services\Finance\ChartOfAccounts;
use App\Support\CurrentHospital;
use Illuminate\Console\Command;

/**
 * Bring every hospital's books up to date with what it has done
 * (docs/finance.md).
 *
 * Run once after the books are deployed, so a hospital that has been invoicing
 * for a year opens its finance pages to a year of figures rather than to
 * nothing. Safe to run again at any time: posting is idempotent, and a second
 * run finds nothing to post.
 */
class BackfillBooks extends Command
{
    protected $signature = 'finance:backfill {--hospital= : Only this hospital (id or slug)}';

    protected $description = 'Post every invoice, payment, card, insurer and stock movement not yet in its hospital\'s books';

    public function handle(BooksBackfill $backfill, CurrentHospital $current, ChartOfAccounts $chart): int
    {
        $only = $this->option('hospital');

        $hospitals = Hospital::query()
            ->when(filled($only), fn ($q) => $q->where(fn ($w) => $w->where('id', $only)->orWhere('slug', $only)))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($hospitals->isEmpty()) {
            $this->warn('No hospital matched.');

            return self::FAILURE;
        }

        foreach ($hospitals as $hospital) {
            $posted = $current->within($hospital->id, function () use ($backfill, $chart) {
                $chart->ensure();

                return $backfill->run();
            });

            $total = array_sum($posted);
            $this->line(sprintf('%-40s %s', $hospital->name, $total > 0
                ? $total.' posted ('.collect($posted)->filter()->map(fn ($n, $k) => "{$n} ".str_replace('_', ' ', $k))->implode(', ').')'
                : 'up to date'));
        }

        return self::SUCCESS;
    }
}
