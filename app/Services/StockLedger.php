<?php

namespace App\Services;

use App\Enums\StockMovementReason;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;

/**
 * The store's record book, read: the filters of the Stock ledger page and the
 * totals shown beside them. Shared by the web page and the API.
 */
class StockLedger
{
    /** @return list<string> reason values that put stock IN */
    public static function incoming(): array
    {
        return array_values(array_map(
            fn (StockMovementReason $r) => $r->value,
            array_filter(StockMovementReason::cases(), fn (StockMovementReason $r) => $r->isIncoming()),
        ));
    }

    /**
     * @param  array{item?:mixed,reason?:?string,direction?:?string,from?:?string,to?:?string,search?:?string}  $f
     * @return Builder<StockMovement>
     */
    public static function filtered(array $f): Builder
    {
        $item = (string) ($f['item'] ?? '');
        $reason = (string) ($f['reason'] ?? '');
        $direction = (string) ($f['direction'] ?? '');
        $from = (string) ($f['from'] ?? '');
        $to = (string) ($f['to'] ?? '');
        $search = (string) ($f['search'] ?? '');
        $incoming = self::incoming();

        return StockMovement::query()
            ->when($item !== '', fn (Builder $q) => $q->where('stock_item_id', (int) $item))
            ->when($reason !== '', fn (Builder $q) => $q->where('reason', $reason))
            ->when($direction === 'in', fn (Builder $q) => $q->whereIn('reason', $incoming))
            ->when($direction === 'out', fn (Builder $q) => $q->whereNotIn('reason', $incoming))
            ->when($from !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $to))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->whereHas('stockItem', fn (Builder $i) => $i->where('name', 'like', "%{$search}%"))
                ->orWhere('note', 'like', "%{$search}%")));
    }

    /**
     * What the filtered period moved, both ways. Summed over the filtered set
     * because a figure means nothing without the rows it belongs to.
     *
     * @param  Builder<StockMovement>  $base
     * @return array{in:string,out:string,rows:int,bought:string,sold:string,margin:string,lost:string,unpriced:int}
     */
    public static function totals(Builder $base): array
    {
        $incoming = self::incoming();

        // Money is summed in PHP over the rows: a movement's worth is quantity
        // x the price IT happened under, and no column holds that product.
        $priced = (clone $base)->select(['reason', 'quantity', 'unit_cost', 'unit_price'])->get();

        $bought = '0';
        $sold = '0';
        $margin = '0';
        $lost = '0';
        $unpriced = 0;

        foreach ($priced as $movement) {
            $cost = $movement->costValue();

            if ($cost === null) {
                $unpriced++;

                continue;
            }

            if ($movement->reason->isIncoming()) {
                $bought = bcadd($bought, $cost, 2);

                continue;
            }

            if ($movement->reason->isLoss()) {
                // A write-off is a loss at COST, never at the selling price.
                $lost = bcadd($lost, $cost, 2);

                continue;
            }

            $sale = $movement->saleValue();

            if ($sale === null) {
                $unpriced++;

                continue;
            }

            $sold = bcadd($sold, $sale, 2);
            $margin = bcadd($margin, bcsub($sale, $cost, 2), 2);
        }

        return [
            'in' => (string) ((clone $base)->whereIn('reason', $incoming)->sum('quantity') ?: '0'),
            'out' => (string) ((clone $base)->whereNotIn('reason', $incoming)->sum('quantity') ?: '0'),
            'rows' => $priced->count(),
            'bought' => $bought,
            'sold' => $sold,
            'margin' => $margin,
            'lost' => $lost,
            'unpriced' => $unpriced,
        ];
    }
}
