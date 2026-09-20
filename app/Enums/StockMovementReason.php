<?php

namespace App\Enums;

/**
 * Why stock moved. Each reason has a fixed direction (+1 in / −1 out) — the
 * single source of truth StockService uses to apply a movement. Replaces the
 * legacy boot-hook quantity recalculation with an explicit, audited ledger.
 */
enum StockMovementReason: string
{
    case OpeningStock = 'opening_stock';
    case Received = 'received';
    case ReturnedToStock = 'returned';
    case AdjustmentIn = 'adjustment_in';
    case Dispensed = 'dispensed';
    case Wastage = 'wastage';
    case AdjustmentOut = 'adjustment_out';

    // Why stock is LOST, in the words a storekeeper would use. "Adjustment
    // (out), 400 tablets" is a number nobody can act on; "expired" is a
    // shelf-life problem, "damaged" is a handling problem and "stolen" is a
    // security problem, and a store that cannot tell them apart cannot fix
    // any of them.
    case Expired = 'expired';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case ReturnedToSupplier = 'returned_to_supplier';

    public function label(): string
    {
        return match ($this) {
            self::OpeningStock => 'Opening stock',
            self::Received => 'Received',
            self::ReturnedToStock => 'Returned to stock',
            self::AdjustmentIn => 'Adjustment (in)',
            self::Dispensed => 'Dispensed',
            self::Wastage => 'Wastage',
            self::AdjustmentOut => 'Adjustment (out)',
            self::Expired => 'Expired',
            self::Damaged => 'Damaged',
            self::Lost => 'Lost or stolen',
            self::ReturnedToSupplier => 'Returned to supplier',
        };
    }

    /** +1 increases stock, −1 decreases it. */
    public function sign(): int
    {
        return match ($this) {
            self::OpeningStock, self::Received, self::ReturnedToStock, self::AdjustmentIn => 1,
            self::Dispensed, self::Wastage, self::AdjustmentOut,
            self::Expired, self::Damaged, self::Lost, self::ReturnedToSupplier => -1,
        };
    }

    public function isIncoming(): bool
    {
        return $this->sign() === 1;
    }

    /**
     * Stock that left the shelf without being sold — the store's own losses.
     *
     * Dispensing is not here: that went to a patient and is on a bill.
     *
     * @return list<self>
     */
    public static function losses(): array
    {
        return [self::Expired, self::Damaged, self::Lost, self::Wastage];
    }

    public function isLoss(): bool
    {
        return in_array($this, self::losses(), true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
