<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'invoice_no' => $this->invoice_no,
            'patient' => ['uuid' => $this->patient?->uuid, 'name' => $this->patient?->full_name, 'patient_no' => $this->patient?->patient_no],
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount' => $this->discount,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance,
            'status' => $this->status->value,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'description' => $it->description,
                'quantity' => $it->quantity,
                'unit_price' => $it->unit_price,
                'line_total' => $it->line_total,
            ])->values()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->sortByDesc('id')->map(fn ($p) => [
                'amount' => $p->amount,
                'method' => $p->method->value,
                'reference' => $p->reference,
                'received_by' => $p->receivedBy?->name,
                'at' => $p->created_at?->toIso8601String(),
            ])->values()),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
