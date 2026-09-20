<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $unit_price
 * @property numeric-string $line_total
 */
class InvoiceItem extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'invoice_id', 'description', 'quantity', 'unit_price', 'line_total',
        'tax_exempt', 'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'tax_exempt' => 'boolean',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
