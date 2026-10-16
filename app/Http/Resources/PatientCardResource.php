<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The card number is encrypted at rest and never leaves the server: a card is
 * found by member number or the patient, and shown masked (last four).
 *
 * @mixin \App\Models\PatientCard
 */
class PatientCardResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'masked' => $this->masked(),
            'last4' => substr(preg_replace('/\D/', '', (string) $this->card_number), -4),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'can_transact' => $this->status->canTransact() && ! $this->isExpired(),
            'balance' => (string) $this->balance,
            'accepts_credit' => $this->accepts_credit,
            'max_credit' => (string) $this->max_credit,
            'expiry' => $this->expiry?->toDateString(),
            'is_expired' => $this->isExpired(),
            'is_insurance' => $this->isInsurance(),
            'member_no' => $this->member_no,
            'patient' => $this->whenLoaded('patient', fn () => ['uuid' => $this->patient?->uuid, 'name' => $this->patient?->full_name, 'patient_no' => $this->patient?->patient_no]),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'name' => $this->provider->name] : null),
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer?->name),
            'active_holders' => $this->whenCounted('holders'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
