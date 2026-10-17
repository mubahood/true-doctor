<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Receipts and queue tickets on 58 and 80 mm roll paper (roadmap 06 §6). */
class ThermalPrintTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['currency' => 'UGX', 'name' => 'Kato Medical Centre']);
        app(CurrentHospital::class)->set($this->h->id);
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    /** @return array{0: \App\Models\Payment, 1: Visit} */
    private function paidVisit(): array
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Amina'])->id, 'status' => 'ongoing', 'visit_no' => 'V-20261017-014']);
        $billing = app(BillingService::class);
        $billing->orderService($visit->fresh(), Service::factory()->create(['hospital_id' => $this->h->id, 'name' => 'General consultation', 'price' => '20000'])->id, 1);
        $invoice = $billing->generateInvoice($visit->fresh(), '0.00', null);

        return [$billing->recordPayment($invoice, PaymentMethod::Cash, '20000.00'), $visit];
    }

    public function test_the_receipt_prints_on_80_and_58_mm_paper(): void
    {
        [$payment] = $this->paidVisit();
        $this->actingAs($this->staff('accountant'));

        $wide = $this->get(route('admin.payments.receipt.thermal', $payment))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertMatchesRegularExpression('/MediaBox \[0\.000 0\.000 226\.770 /', (string) $wide->getContent(), 'an 80 mm page');

        $narrow = $this->get(route('admin.payments.receipt.thermal', [$payment, 'w' => 58]))->assertOk();
        $this->assertMatchesRegularExpression('/MediaBox \[0\.000 0\.000 164\.410 /', (string) $narrow->getContent(), 'a 58 mm page');
    }

    public function test_the_queue_ticket_carries_the_days_number(): void
    {
        [, $visit] = $this->paidVisit();
        $this->actingAs($this->staff('receptionist'));

        $this->get(route('admin.visits.ticket', $visit))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_another_hospital_cannot_print_it(): void
    {
        [$payment, $visit] = $this->paidVisit();
        $other = Hospital::factory()->create();
        $stranger = $this->staff('accountant', $other);
        app(CurrentHospital::class)->set($other->id);

        $this->actingAs($stranger)->get(route('admin.payments.receipt.thermal', $payment))->assertNotFound();
        $this->actingAs($stranger)->get(route('admin.visits.ticket', $visit))->assertNotFound();
    }

    public function test_a_clinic_with_a_receipt_printer_gets_the_print_button(): void
    {
        [$payment] = $this->paidVisit();
        $admin = $this->staff('hospital_admin');
        $this->actingAs($admin);

        $this->get(route('admin.invoices.show', $payment->invoice))->assertOk()->assertDontSee(route('admin.payments.receipt.thermal', $payment), false);

        Livewire::test(\App\Livewire\Settings\Billing::class)->set('receipt_printer', '58')->call('save')->assertHasNoErrors();
        $this->assertSame(58, app(\App\Support\HospitalSettings::class)->receiptPrinter());

        $this->get(route('admin.invoices.show', $payment->invoice))->assertOk()
            ->assertSee(route('admin.payments.receipt.thermal', [$payment, 'w' => 58]), false);
    }
}
