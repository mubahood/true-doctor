<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use App\Models\Contracts\NamesALedgerParty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a delivery, with the batch and expiry printed on the box. It is
 * the source of the stock movement that put it on the shelf. Append-only.
 *
 * @property numeric-string $quantity
 * @property numeric-string $unit_cost
 * @property numeric-string $line_total
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class GoodsReceiptItem extends Model implements NamesALedgerParty
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'goods_receipt_id', 'purchase_order_item_id', 'stock_item_id', 'quantity', 'unit_cost',
        'line_total', 'batch_no', 'expiry_date', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'expiry_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class)->withTrashed();
    }

    /** @return HasMany<SupplierReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /** What has already gone back to the supplier from this line. */
    public function returnedQuantity(): string
    {
        return bcadd((string) ($this->returns()->sum('quantity') ?: '0'), '0', 2);
    }

    /** What may still be sent back: received less already returned. */
    public function returnable(): string
    {
        $left = bcsub((string) $this->quantity, $this->returnedQuantity(), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    public function ledgerParty(): ?string
    {
        return $this->receipt?->supplier?->name;
    }

    public function ledgerReference(): ?string
    {
        $receipt = $this->receipt;
        if ($receipt === null) {
            return null;
        }

        return $receipt->supplier_invoice_no ? $receipt->grn_no.' / '.$receipt->supplier_invoice_no : $receipt->grn_no;
    }
}
