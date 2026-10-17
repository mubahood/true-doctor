<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Someone the hospital buys stock from (docs/procurement.md).
 *
 * @property int|null $payment_terms_days
 * @property bool $is_active
 */
class Supplier extends Model
{
    use BelongsToHospital, SoftDeletes;

    protected $fillable = [
        'uuid', 'name', 'contact_person', 'phone', 'email', 'address', 'tin',
        'payment_terms_days', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Supplier $supplier) {
            $supplier->uuid ??= (string) Str::uuid();
        });
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return HasMany<SupplierReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /**
     * What has come in from this supplier and what has gone back, at cost.
     *
     * @return array{orders:int,open:int,received:string,returned:string}
     */
    public function figures(): array
    {
        return [
            'orders' => $this->purchaseOrders()->count(),
            'open' => $this->purchaseOrders()->whereIn('status', ['ordered', 'part_received'])->count(),
            'received' => bcadd((string) ($this->receipts()->sum('total') ?: '0'), '0', 2),
            'returned' => bcadd((string) ($this->returns()->sum('total') ?: '0'), '0', 2),
        ];
    }

    /** A supplier with orders is history: archive is refused, deactivate instead. */
    public function deletionBlocker(): ?string
    {
        return $this->purchaseOrders()->exists()
            ? 'This supplier has purchase orders and cannot be deleted. Deactivate it instead.'
            : null;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
