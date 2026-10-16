<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Who owes what, oldest first. "Owes" is Invoice::outstanding() - the same
 * scope the reports and the patient's record read - and the age is days since
 * the invoice was raised (PatientSummaryService::aging's basis). Only the bands
 * are the debt collector's: 0-30, 31-60, 61-90, 90+.
 */
class DebtorsService
{
    /** @var list<string> */
    public const BUCKETS = ['0-30', '31-60', '61-90', '90+'];

    public static function bucketOf(int $ageDays): string
    {
        return match (true) {
            $ageDays <= 30 => '0-30',
            $ageDays <= 60 => '31-60',
            $ageDays <= 90 => '61-90',
            default => '90+',
        };
    }

    /** @return LengthAwarePaginator<int,Invoice> */
    public function page(?string $bucket, ?string $term, int $perPage): LengthAwarePaginator
    {
        $q = Invoice::query()->outstanding()->with('patient')->matching($term);

        if ($bucket !== null && $bucket !== '') {
            $this->inBucket($q, $bucket);
        }

        return $q->orderBy('created_at')->orderBy('id')->paginate($perPage);
    }

    /**
     * What is owed in each band, over everything outstanding (not the page).
     *
     * @return array{aging:array<string,string>,total_outstanding:string,count:int}
     */
    public function aging(): array
    {
        $aging = array_fill_keys(self::BUCKETS, '0.00');
        $total = '0.00';
        $count = 0;
        $now = Carbon::now();

        // One SUM per band in the database: a clinic with thousands of open
        // invoices must not load them all into PHP on every page of the list.
        foreach (self::BUCKETS as $band) {
            $q = Invoice::query()->outstanding();
            $this->inBucket($q, $band);
            $row = $q->selectRaw('COUNT(*) as n, COALESCE(SUM(balance), 0) as s')->first();
            $sum = bcadd((string) ($row->s ?? '0'), '0', 2);
            $aging[$band] = $sum;
            $total = bcadd($total, $sum, 2);
            $count += (int) ($row->n ?? 0);
        }

        return ['aging' => $aging, 'total_outstanding' => $total, 'count' => $count];
    }

    public function ageDays(Invoice $invoice): int
    {
        return (int) $invoice->created_at->diffInDays(Carbon::now());
    }

    /**
     * age (whole days) <= 30 is "created after now-31d", and so on; the
     * boundaries are those of bucketOf().
     *
     * @param  Builder<Invoice>  $q
     */
    private function inBucket(Builder $q, string $bucket): void
    {
        $at = fn (int $days) => Carbon::now()->subDays($days);

        match ($bucket) {
            '0-30' => $q->where('created_at', '>', $at(31)),
            '31-60' => $q->where('created_at', '<=', $at(31))->where('created_at', '>', $at(61)),
            '61-90' => $q->where('created_at', '<=', $at(61))->where('created_at', '>', $at(91)),
            default => $q->where('created_at', '<=', $at(91)),
        };
    }
}
