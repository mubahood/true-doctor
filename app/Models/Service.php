<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A price-list entry. Prices are hospital-owned (admin-set, in the hospital's
 * configured currency); a snapshot is copied onto each ordered line.
 *
 * @property numeric-string $price
 * @property bool $tax_exempt
 * @property bool $is_active
 * @property string|null $category the specialty it is listed under ("Dental")
 * @property int|null $ledger_account_id the income it earns into; null = by kind of order
 */
class Service extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'category', 'ledger_account_id', 'price', 'tax_exempt', 'is_active'];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<LedgerAccount, $this> */
    public function incomeAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'tax_exempt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
