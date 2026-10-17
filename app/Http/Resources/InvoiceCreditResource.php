<?php

namespace App\Http\Resources;

use App\Support\HospitalSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InvoiceCredit */
class InvoiceCreditResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $invoice = $this->relationLoaded('invoice') ? $this->invoice : null;

        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount' => (string) $this->amount,
            'amount_label' => HospitalSettings::money($this->amount),
            'tax_part' => (string) $this->tax_part,
            'method' => $this->method?->value,
            'reference' => $this->reference,
            'reason' => $this->reason,
            'invoice' => $invoice === null ? null : ['uuid' => $invoice->uuid, 'invoice_no' => $invoice->invoice_no],
            'patient' => $this->relationLoaded('patient') && $this->patient !== null
                ? ['uuid' => $this->patient->uuid, 'name' => $this->patient->full_name, 'patient_no' => $this->patient->patient_no]
                : null,
            'requested_by' => $this->relationLoaded('requestedBy') ? $this->requestedBy?->name : null,
            'requested_at' => $this->requested_at->toIso8601String(),
            'decided_by' => $this->relationLoaded('decidedBy') ? $this->decidedBy?->name : null,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'can_approve' => $this->isPending() && (bool) $request->user()?->can('approve', $this->resource),
        ];
    }
}
