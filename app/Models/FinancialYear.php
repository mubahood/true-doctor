<?php

namespace App\Models;

use App\Enums\FinancialYearStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property FinancialYearStatus $status
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon $ends_on
 */
class FinancialYear extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = ['name', 'starts_on', 'ends_on', 'status', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date',
            'status' => FinancialYearStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function closedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === FinancialYearStatus::Open;
    }

    public function contains(\Illuminate\Support\Carbon $date): bool
    {
        return $date->betweenIncluded($this->starts_on->startOfDay(), $this->ends_on->endOfDay());
    }
}
