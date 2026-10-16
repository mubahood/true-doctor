<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Service */
class ServiceResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'category' => $this->category,
            'ledger_account_id' => $this->ledger_account_id,
            'income_account' => $this->whenLoaded('incomeAccount', fn () => $this->incomeAccount?->name),
            // A fixed price, or the start of a range (max_price set and above it).
            'price' => $this->price,
            'max_price' => $this->max_price,
            'has_range' => $this->hasRange(),
            'price_label' => $this->priceLabel(),
            'tax_exempt' => (bool) $this->tax_exempt,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
