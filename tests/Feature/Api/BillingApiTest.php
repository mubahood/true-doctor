<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bill_is_invoiced_paid_with_a_traceable_reference_and_printed(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($h->id);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $h->id])->id, 'status' => 'ongoing']);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Consultation', 'price' => '30000.00']);
        app(BillingService::class)->orderService($visit->fresh(), $svc->id, 1);

        $clerk = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $clerk->syncSpatieRole();
        Sanctum::actingAs($clerk);

        $bill = $this->getJson("/api/v1/visits/{$visit->uuid}/bill")->assertOk();
        $bill->assertJsonPath('data.lines.0.name', 'Consultation')->assertJsonPath('data.invoice', null)->assertJsonPath('data.can_invoice', true);
        $this->assertNotContains('flutterwave', collect($bill->json('data.methods'))->pluck('value')->all(), 'a gateway reports its own payments');

        $invoice = $this->postJson("/api/v1/visits/{$visit->uuid}/invoice")->assertCreated()->json('data');
        $this->postJson("/api/v1/visits/{$visit->uuid}/invoice")->assertStatus(422);

        // A transfer with no reference cannot be traced later.
        $this->postJson("/api/v1/invoices/{$invoice['uuid']}/payments", ['method' => 'bank', 'amount' => 10000])
            ->assertStatus(422)->assertJsonPath('errors.reference.0', 'A reference is what proves this payment later.');
        $this->postJson("/api/v1/invoices/{$invoice['uuid']}/payments", ['method' => 'flutterwave', 'amount' => 10000])->assertStatus(422);
        $this->postJson("/api/v1/invoices/{$invoice['uuid']}/payments", ['method' => 'cash', 'amount' => 50000])->assertStatus(422);

        $paid = $this->postJson("/api/v1/invoices/{$invoice['uuid']}/payments", ['method' => 'mobile_money', 'amount' => 10000, 'reference' => 'MM-8812'])
            ->assertCreated()->assertJsonPath('data.balance', '20000.00');

        $this->get("/api/v1/invoices/{$invoice['uuid']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/v1/payments/'.$paid->json('data.payment_uuid').'/receipt')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
