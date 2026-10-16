<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StockCategory */
class StockCategoryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            // The shelf figures, where the list selected them (withShelfFigures).
            'items_count' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'active_count' => $this->when(isset($this->active_count), fn () => (int) $this->active_count),
            'low_count' => $this->when(isset($this->low_count), fn () => (int) $this->low_count),
            'expiring_count' => $this->when(isset($this->expiring_count), fn () => (int) $this->expiring_count),
            'on_hand' => $this->when(isset($this->items_count), fn () => number_format((float) $this->on_hand, 2, '.', '')),
            'stock_value' => $this->when(isset($this->items_count), fn () => number_format((float) $this->stock_value, 2, '.', '')),
        ];
    }
}
