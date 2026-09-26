<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StockItem */
class StockItemResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            // What a dispensing line names (DispensationRequest takes the id).
            'server_id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'current_quantity' => $this->current_quantity,
            'cost_price' => $this->cost_price,
            'sale_price' => $this->sale_price,
            'current_stock_value' => $this->current_stock_value,
            'reorder_level' => $this->reorder_level,
            'batch_no' => $this->batch_no,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_low_stock' => $this->isLowStock(),
            'is_expired' => $this->isExpired(),
        ];
    }
}
