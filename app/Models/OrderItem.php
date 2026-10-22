<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One billable line on an order — what the work actually used.
 *
 * It reaches a visit through its order, exactly as an insurance claim reaches
 * one through its invoice (docs/visits.md), so nothing floats free. It points
 * at either a priced catalogue entry (`service_id`) or a pharmacy item
 * (`stock_item_id`); name and unit_price are snapshots taken at the time, and
 * line_total is maintained by BillingService in bcmath, never by a model hook.
 *
 * @property numeric-string $unit_price
 * @property numeric-string $quantity
 * @property numeric-string $line_total
 * @property numeric-string|null $unit_cost
 * @property bool $billed
 * @property \Illuminate\Support\Carbon|null $billing_set_at
 * @property OrderItemStatus $status
 */
class OrderItem extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id', 'service_id', 'stock_item_id', 'name', 'unit_price', 'quantity',
        'line_total', 'tax_exempt', 'status', 'ordered_by', 'updated_by', 'notes',
        'billed', 'unit_cost', 'billing_set_by', 'billing_set_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'decimal:2',
            'tax_exempt' => 'boolean',
            'status' => OrderItemStatus::class,
            'billed' => 'boolean',
            'unit_cost' => 'decimal:2',
            'billing_set_at' => 'datetime',
        ];
    }

    /**
     * Whether this line reaches the patient's bill.
     *
     * A product can be used without being billed (docs/orders.md): its stock
     * still leaves the shelf and its cost still reaches the books, but it
     * carries no amount and the invoice leaves it out. A service is always
     * billed. A row loaded without the column counts as billed.
     */
    public function isBilled(): bool
    {
        return $this->billed !== false;
    }

    /**
     * Who last chose whether this line is billed.
     *
     * @return BelongsTo<User, $this>
     */
    public function billingSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billing_set_by');
    }

    /** Whether this line came off the pharmacy shelf rather than the price list. */
    public function isProduct(): bool
    {
        return $this->stock_item_id !== null;
    }

    /**
     * The quantity as someone would write it: 2, not 2.00; 1.5, not 1.50.
     *
     * Quantities are decimal because drugs are dispensed in halves, but almost
     * every line is a whole number and printing the noughts makes a column of
     * them unreadable.
     */
    public function tidyQuantity(): string
    {
        $qty = (string) $this->quantity;

        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<User, $this> */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /**
     * The last hand on this line, when it is not the first.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Whether anybody has corrected this line since it was put on.
     *
     * Named away from Eloquent's own wasChanged(), which asks about the
     * current request rather than about the record.
     */
    public function wasCorrected(): bool
    {
        return $this->updated_by !== null;
    }
}
