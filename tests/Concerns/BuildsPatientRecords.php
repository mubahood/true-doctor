<?php

namespace Tests\Concerns;

use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;

/**
 * Money and visits for a patient, built through the services the rest of the
 * system builds them with - there is no invoice factory, and a hand-rolled row
 * would be a different shape from the one the page reads.
 */
trait BuildsPatientRecords
{
    /** A raised invoice of `$amount` for the patient, optionally back-dated. */
    protected function invoiceFor(Patient $patient, string $amount, ?int $daysAgo = null, ?int $by = null): Invoice
    {
        $visit = Visit::factory()->create(['hospital_id' => $patient->hospital_id, 'patient_id' => $patient->id]);
        $service = Service::factory()->create(['hospital_id' => $patient->hospital_id, 'price' => $amount, 'tax_exempt' => true]);
        app(BillingService::class)->orderService($visit, $service->id, 1, $by);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $by);

        if ($daysAgo !== null) {
            Invoice::whereKey($invoice->id)->update(['created_at' => now()->subDays($daysAgo)]);
        }

        return $invoice->fresh();
    }

    protected function pay(Invoice $invoice, string $amount, ?int $by = null)
    {
        return app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, $amount, [], $by);
    }

    protected function patientOf(Hospital $h, array $attrs = []): Patient
    {
        return Patient::factory()->create(['hospital_id' => $h->id] + $attrs);
    }
}
