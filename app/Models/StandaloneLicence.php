<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @property \Illuminate\Support\Carbon $updates_until */
/**
 * A licence to run one standalone installation (docs/proposals/standalone-edition.md §5).
 *
 * @property string $id
 * @property int $product_id
 * @property string $customer_name
 * @property string $customer_email
 * @property list<string> $domains
 * @property \Illuminate\Support\Carbon|null $domain_changed_at
 * @property array<string,int>|null $limits
 * @property \Illuminate\Support\Carbon $updates_until
 * @property string $status active | revoked
 * @property string $source online | offline
 * @property string|null $reference
 * @property-read StandaloneProduct|null $product
 */
class StandaloneLicence extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'product_id', 'customer_name', 'customer_email', 'domains', 'domain_changed_at', 'limits', 'updates_until', 'status', 'source', 'reference'];

    protected function casts(): array
    {
        return ['domains' => 'array', 'domain_changed_at' => 'datetime', 'limits' => 'array', 'updates_until' => 'date'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<StandaloneProduct, $this> */
    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StandaloneProduct::class, 'product_id');
    }

    public function domain(): string
    {
        return (string) ($this->domains[0] ?? '');
    }

    /** A buyer may move their licence themselves once in this many days. */
    public const DOMAIN_CHANGE_DAYS = 30;

    public function canChangeDomain(): bool
    {
        return $this->domain_changed_at === null || $this->domain_changed_at->lt(now()->subDays(self::DOMAIN_CHANGE_DAYS));
    }

    protected static function booted(): void
    {
        static::creating(function (self $licence): void {
            $licence->id ??= (string) Str::uuid();
        });
    }
}
