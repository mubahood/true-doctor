<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property numeric-string $amount_ugx */
/**
 * A purchase or renewal, from checkout to verified payment.
 *
 * @property int $id
 * @property int $product_id
 * @property string $kind purchase | renewal
 * @property string|null $licence_id
 * @property string $reference
 * @property string|null $gateway_tracking_id
 * @property string $customer_name
 * @property string $customer_email
 * @property string $domain
 * @property numeric-string $amount_ugx
 * @property int $updates_months
 * @property array<string,int>|null $limits
 * @property string $status pending | paid | failed | mismatch
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
class StandaloneOrder extends Model
{
    protected $fillable = ['product_id', 'kind', 'licence_id', 'reference', 'gateway_tracking_id', 'customer_name', 'customer_email', 'domain', 'amount_ugx', 'updates_months', 'limits', 'status', 'paid_at'];

    protected function casts(): array
    {
        return ['amount_ugx' => 'decimal:2', 'limits' => 'array', 'paid_at' => 'datetime'];
    }
}
