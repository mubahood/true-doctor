<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An insurer the hospital deals with.
 *
 * Two arrangements hang off one directory entry, and they are not the same
 * thing (docs/cards.md): a CLAIM settles one invoice for one patient, while a
 * FLOAT funds member cards in bulk and is billed for the usage afterwards.
 *
 * `float_balance` is a cache of `insurance_transactions`, moved only inside
 * InsuranceLedgerService under a lock on this row.
 *
 * @property bool $is_active
 * @property string $float_balance
 * @property string $default_credit_limit
 */
class InsuranceProvider extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'contact_person', 'contact_phone', 'contact_email',
        'is_active', 'default_credit_limit',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'float_balance' => 'decimal:2',
            'default_credit_limit' => 'decimal:2',
        ];
    }

    /** @return HasMany<InsuranceTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(InsuranceTransaction::class)->latest('created_at')->latest('id');
    }

    /** @return HasMany<PatientCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(PatientCard::class);
    }

    /** @return HasMany<PatientInsurance, $this> */
    public function coverages(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    /** What its members owe across every card it stands behind. */
    public function outstanding(): string
    {
        $owed = $this->cards()->where('balance', '<', 0)->sum('balance');

        return bcmul(number_format((float) $owed, 2, '.', ''), '-1', 2);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
