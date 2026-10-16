<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPatientRecords;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * GET admin/patients/{patient}/statement - the statement of account. The
 * every-document rules (inline, letterhead, size, no public folder) are held in
 * GeneratedDocumentTest; this is what is particular to it.
 */
class PatientStatementTest extends TestCase
{
    use BuildsPatientRecords, InteractsWithTenant, RefreshDatabase;

    public function test_it_opens_inline_as_a_pdf_named_after_the_patient(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('accountant', $h);
        $p = $this->patientOf($h);
        $this->invoiceFor($p, '30000.00', 40, $admin->id);

        $response = $this->get(route('admin.patients.statement', $p));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringStartsWith('inline', $disposition);
        $this->assertStringContainsString('statement-of-account-'.$p->patient_no.'.pdf', $disposition);
    }

    public function test_it_renders_the_account_the_page_shows(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('accountant', $h);
        $p = $this->patientOf($h);
        $paid = $this->invoiceFor($p, '30000.00', 40, $admin->id);
        $this->pay($paid, '12000.00', $admin->id);
        $void = $this->invoiceFor($p, '77000.00', null, $admin->id);
        Invoice::whereKey($void->id)->update(['status' => 'void']);

        $html = view('pdf.patient-statement', [
            'patient' => $p,
            's' => app(\App\Services\PatientSummaryService::class)->statement($p),
            'asAt' => now(),
        ])->render();

        $this->assertStringContainsString('Statement of account', $html);
        $this->assertStringContainsString($p->patient_no, $html);
        $this->assertStringContainsString($paid->invoice_no, $html);
        $this->assertStringNotContainsString($void->invoice_no, $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('18,000', $html);
        $this->assertStringContainsString('31-60 days', $html);
    }

    public function test_it_needs_billing_view(): void
    {
        $h = $this->hospital();
        $p = $this->patientOf($h);

        $this->actingAsRole('pharmacist', $h);
        $this->get(route('admin.patients.statement', $p))->assertForbidden();

        $this->actingAsRole('receptionist', $h);
        $this->get(route('admin.patients.statement', $p))->assertOk();
    }

    public function test_it_will_not_print_another_hospitals_patient(): void
    {
        $a = $this->hospital();
        $b = $this->hospital();
        $patientB = $this->patientOf($b);

        $this->actingAsRole('hospital_admin', $a);

        $this->get(route('admin.patients.statement', $patientB))->assertNotFound();
    }

    public function test_a_patient_with_no_account_still_gets_a_statement(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('accountant', $h);
        $p = $this->patientOf($h);

        $this->get(route('admin.patients.statement', $p))->assertOk();
    }
}
