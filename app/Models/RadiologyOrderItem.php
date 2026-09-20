<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property numeric-string $price */
class RadiologyOrderItem extends Model
{
    use BelongsToHospital;

    protected $fillable = ['radiology_order_id', 'radiology_study_id', 'name', 'modality', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    /** @return BelongsTo<RadiologyOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(RadiologyOrder::class, 'radiology_order_id');
    }
}
