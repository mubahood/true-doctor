<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string,mixed>|null $meta
 * @property numeric-string $amount
 */
class GatewayLog extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'invoice_id', 'payment_id', 'provider', 'tx_ref', 'provider_ref',
        'status', 'amount', 'currency', 'payment_type', 'verified', 'meta',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified' => 'boolean', 'meta' => 'array'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isSettled(): bool
    {
        return $this->status === 'successful';
    }
}
