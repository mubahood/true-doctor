<?php

namespace App\Services;

use App\Enums\StockMovementReason;
use App\Models\StockItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * The inventory alerts board, as data: four lanes, worst first, with the
 * figures that make somebody act. The web's Stock alerts page and the API both
 * read it from here, so they cannot disagree about what "running out" means.
 *
 *  1. out of stock   - nothing left at all; work stopping now.
 *  2. expired        - still on the books; to destroy, not to order.
 *  3. low            - at or below the reorder level but not empty.
 *  4. expiring       - still usable inside the horizon, use it first.
 */
class StockAlertBoard
{
    /** Days ahead counted as "expiring soon". */
    public const EXPIRY_HORIZON_DAYS = StockItem::EXPIRY_HORIZON_DAYS;

    /** ...and the urgent end of it, where a plan has to become an action. */
    public const EXPIRY_URGENT_DAYS = 30;

    /** @var array<string,mixed> */
    private array $memo = [];

    public function __construct(private readonly ?int $categoryId = null) {}

    public static function forCategory(int|string|null $category): self
    {
        return new self(($category ?? '') !== '' && (int) $category > 0 ? (int) $category : null);
    }

    /** @return Builder<StockItem> */
    private function onShelf(): Builder
    {
        return StockItem::with('category')
            ->where('is_active', true)
            ->when($this->categoryId !== null, fn (Builder $q) => $q->where('stock_category_id', $this->categoryId));
    }

    /** @return Collection<int,StockItem> */
    public function outOfStock(): Collection
    {
        return $this->memo['out'] ??= $this->onShelf()->where('current_quantity', '<=', 0)->orderBy('name')->get();
    }

    /** @return Collection<int,StockItem> */
    public function lowStock(): Collection
    {
        return $this->memo['low'] ??= StockItem::applyLowStock($this->onShelf()->where('current_quantity', '>', 0))->orderBy('name')->get();
    }

    /** Expiring soon, but not yet expired. @return Collection<int,StockItem> */
    public function expiring(): Collection
    {
        return $this->memo['expiring'] ??= StockItem::applyExpiringBefore(
            $this->onShelf()->where('current_quantity', '>', 0)->whereDate('expiry_date', '>', now()->toDateString()),
            now()->addDays(self::EXPIRY_HORIZON_DAYS)->toDateString(),
        )->orderBy('expiry_date')->get();
    }

    /** @return Collection<int,StockItem> */
    public function expired(): Collection
    {
        return $this->memo['expired'] ??= StockItem::applyExpiringBefore(
            $this->onShelf()->where('current_quantity', '>', 0),
            now()->toDateString(),
        )->orderBy('expiry_date')->get();
    }

    /**
     * What is at risk: counts, what the expired stock is worth, and what it
     * would cost to put the shortages right.
     *
     * @return array{out:int,low:int,expiring:int,expired:int,value:string,shortfall:string}
     */
    public function atRisk(): array
    {
        $shortfall = '0';

        foreach ($this->outOfStock()->concat($this->lowStock()) as $item) {
            $short = $this->shortBy($item);

            if (bccomp($short, '0', 2) > 0) {
                $shortfall = bcadd($shortfall, bcmul($short, (string) $item->cost_price, 2), 2);
            }
        }

        return [
            'out' => $this->outOfStock()->count(),
            'low' => $this->lowStock()->count(),
            'expiring' => $this->expiring()->count(),
            'expired' => $this->expired()->count(),
            'value' => (string) $this->expired()->sum(fn (StockItem $i) => (float) $i->current_stock_value),
            'shortfall' => $shortfall,
        ];
    }

    /** How many days until it goes off - negative once it has. */
    public static function daysLeft(StockItem $item): ?int
    {
        return $item->expiry_date === null
            ? null
            : (int) now()->startOfDay()->diffInDays($item->expiry_date->startOfDay(), false);
    }

    /** '' or 'warn' - whether an expiry has stopped being a plan. */
    public static function expiryTone(StockItem $item): string
    {
        $days = self::daysLeft($item);

        return $days !== null && $days <= self::EXPIRY_URGENT_DAYS ? 'warn' : '';
    }

    /** How much would bring this line back up to its reorder level. */
    public static function shortBy(StockItem $item): string
    {
        $short = bcsub((string) $item->reorder_level, (string) $item->current_quantity, 2);

        return bccomp($short, '0', 2) > 0 ? $short : '0';
    }

    /** What it would cost to order that much. */
    public static function shortfallValue(StockItem $item): string
    {
        return bcmul(self::shortBy($item), (string) $item->cost_price, 2);
    }

    /**
     * Write off several expired lines under one reason. Every line is its own
     * ledger movement; one failing does not take the others with it.
     *
     * @param  iterable<StockItem>  $items  already authorised by the caller
     * @return array{done:int,refused:list<string>}
     */
    public function sweep(iterable $items, StockMovementReason $reason, string $note, ?int $userId, StockService $stock): array
    {
        $done = 0;
        $refused = [];

        foreach ($items as $item) {
            try {
                $stock->adjust($item, $reason, (string) $item->current_quantity, $userId, $note);
                $done++;
            } catch (RuntimeException) {
                $refused[] = $item->name;
            }
        }

        return ['done' => $done, 'refused' => $refused];
    }
}
