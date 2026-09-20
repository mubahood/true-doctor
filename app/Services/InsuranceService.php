<?php

namespace App\Services;

use App\Enums\ClaimStatus;
use App\Enums\PaymentMethod;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Support\CurrentHospital;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Insurance claims lifecycle. A claim moves through ClaimStatus's machine via
 * transition(); reaching Paid records a payment on the linked invoice through
 * BillingService (method=Insurance) — once, inside the same transaction — so the
 * claim's money and the invoice ledger never diverge.
 */
class InsuranceService
{
    public function __construct(private readonly BillingService $billing) {}

    public function createClaim(array $data, ?int $by = null): InsuranceClaim
    {
        if (app(CurrentHospital::class)->id() === null) {
            throw new RuntimeException('Cannot create a claim with no resolved hospital.');
        }
        if (bccomp((string) ($data['amount'] ?? '0'), '0', 2) <= 0) {
            throw new RuntimeException('Claim amount must be greater than zero.');
        }

        return $this->createWithNumber($data, $by);
    }

    private function createWithNumber(array $data, ?int $by, int $attempt = 0): InsuranceClaim
    {
        $now = Carbon::now();

        try {
            return DB::transaction(function () use ($data, $by, $now) {
                $year = (int) $now->format('Y');
                $seq = \App\Support\Sequence::next('claim', (string) $year, fn () => InsuranceClaim::withTrashed()->whereYear('created_at', $year)->count());

                return InsuranceClaim::create([
                    'uuid' => (string) Str::uuid(),
                    'claim_no' => 'CLM-'.$now->format('Y').'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                    'patient_id' => $data['patient_id'],
                    'insurance_provider_id' => $data['insurance_provider_id'],
                    'invoice_id' => $data['invoice_id'] ?? null,
                    'patient_insurance_id' => $data['patient_insurance_id'] ?? null,
                    'amount' => $data['amount'],
                    'status' => ClaimStatus::Draft,
                    'notes' => $data['notes'] ?? null,
                    'resolved_by' => $by,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($attempt >= 3) {
                throw $e;
            }

            return $this->createWithNumber($data, $by, $attempt + 1);
        }
    }

    public function transition(InsuranceClaim $claim, ClaimStatus $to, ?int $by = null, ?string $note = null): InsuranceClaim
    {
        return DB::transaction(function () use ($claim, $to, $by, $note) {
            /** @var InsuranceClaim $locked */
            $locked = InsuranceClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($to)) {
                throw new RuntimeException("Cannot move a claim from {$locked->status->label()} to {$to->label()}.");
            }

            if ($to === ClaimStatus::Submitted) {
                $locked->submitted_at = Carbon::now();
            }
            if ($to->isTerminal()) {
                $locked->resolved_at = Carbon::now();
                $locked->resolved_by = $by;
            }
            if ($note !== null) {
                $locked->notes = $note;
            }

            // A Paid claim settles (part of) its invoice via the billing ledger.
            if ($to === ClaimStatus::Paid && $locked->invoice_id !== null) {
                /** @var Invoice|null $invoice */
                $invoice = Invoice::find($locked->invoice_id);
                if ($invoice !== null && $invoice->status->isPayable()) {
                    $toRecord = bccomp((string) $locked->amount, (string) $invoice->balance, 2) > 0
                        ? (string) $invoice->balance
                        : (string) $locked->amount;
                    if (bccomp($toRecord, '0', 2) > 0) {
                        $payment = $this->billing->recordPayment($invoice, PaymentMethod::Insurance, $toRecord, ['reference' => $locked->claim_no], $by);
                        $locked->payment_id = $payment->id;
                    }
                }
            }

            $locked->status = $to;
            $locked->save();

            return $locked;
        });
    }
}
