<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use App\Support\HospitalSettings;
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

    protected $fillable = ['name', 'code', 'category', 'ledger_account_id', 'price', 'max_price', 'tax_exempt', 'is_active'];

    /** Priced as a range — "50,000 – 80,000" — rather than one figure. */
    public function hasRange(): bool
    {
        return $this->max_price !== null && bccomp((string) $this->max_price, (string) $this->price, 2) === 1;
    }

    /**
     * What a line is charged at. A fixed price is the price, whatever is asked.
     * A range takes the amount asked — the counter decides where in the range
     * this patient falls — or its starting price when nothing is asked, and
     * refuses anything outside it.
     */
    public function chargePrice(string|int|float|null $asked = null): string
    {
        if (! $this->hasRange() || $asked === null || $asked === '') {
            return (string) $this->price;
        }

        $amount = number_format((float) $asked, 2, '.', '');
        if (! is_numeric((string) $asked)
            || bccomp($amount, (string) $this->price, 2) === -1
            || bccomp($amount, (string) $this->max_price, 2) === 1) {
            throw new \RuntimeException(sprintf(
                '%s is charged between %s and %s.',
                $this->name, HospitalSettings::money($this->price), HospitalSettings::money($this->max_price),
            ));
        }

        return $amount;
    }

    /** "USh50,000" or "USh50,000 – 80,000". */
    public function priceLabel(): string
    {
        return $this->hasRange()
            ? HospitalSettings::money($this->price).' – '.HospitalSettings::money($this->max_price)
            : HospitalSettings::money($this->price);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<LedgerAccount, $this> */
    public function incomeAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'tax_exempt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
