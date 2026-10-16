<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** W2-A / W2-E: share links, the receipt as data, the cashier's day, debtors, and the money-endpoint key rule. */
class JourneysMoneyApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['currency' => 'UGX', 'name' => 'Kisenyi Referral']);
        app(CurrentHospital::class)->set($this->h->id);
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function invoice(string $price = '30000.00', ?Hospital $h = null): Invoice
    {
        $h ??= $this->h;
        app(CurrentHospital::class)->set($h->id);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $h->id, 'phone_1' => '0700 111 222'])->id, 'status' => 'ongoing']);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => $price]);
        app(BillingService::class)->orderService($visit->fresh(), $svc->id, 1);

        return app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
    }

    public function test_share_needs_sign_in_and_permission_and_stays_inside_the_hospital(): void
    {
        $invoice = $this->invoice();
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/share")->assertStatus(401);

        Sanctum::actingAs($this->staff('pharmacist'));
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/share")->assertStatus(403);

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('receptionist', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/share")->assertStatus(404);
        $this->deleteJson("/api/v1/invoices/{$invoice->uuid}/share")->assertStatus(404);
    }

    public function test_a_shared_invoice_opens_without_login_expires_and_can_be_revoked(): void
    {
        $invoice = $this->invoice();
        Sanctum::actingAs($this->staff('receptionist'));

        $r = $this->postJson("/api/v1/invoices/{$invoice->uuid}/share")->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at', 'phone', 'whatsapp_text', 'sms_text']])
            ->assertJsonPath('data.phone', '0700 111 222');
        $url = $r->json('data.url');

        $this->assertStringNotContainsString($invoice->patient->last_name, $url, 'no patient detail in the URL');
        $this->assertStringContainsString('/s/i/', $url);
        $this->assertStringContainsString($url, $r->json('data.whatsapp_text'));
        $this->assertStringContainsString($invoice->invoice_no, $r->json('data.sms_text'));
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, \Illuminate\Support\Carbon::parse($r->json('data.expires_at'))->timestamp, 5);

        // Anyone with the link, signed out.
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');

        // A tampered link is refused; so is one past its time.
        $this->get(str_replace('/s/i/', '/s/i/x', $url))->assertStatus(403);
        $this->travel(8)->days();
        $this->get($url)->assertStatus(403);
        $this->travelBack();

        // Revoke: the very same, still-valid link now says it is withdrawn.
        Sanctum::actingAs($this->staff('receptionist'));
        $this->deleteJson("/api/v1/invoices/{$invoice->uuid}/share")->assertOk()->assertJsonPath('data.revoked', true);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertStatus(410);
    }

    public function test_a_receipt_link_receipt_json_and_isolation(): void
    {
        $invoice = $this->invoice();
        $clerk = $this->staff('receptionist');
        $payment = app(BillingService::class)->recordPayment($invoice, PaymentMethod::MobileMoney, '10000.00', ['reference' => 'MM-1'], $clerk->id);
        Sanctum::actingAs($clerk);

        $this->postJson("/api/v1/payments/{$payment->uuid}/share")->assertOk()
            ->assertJsonPath('data.phone', '0700 111 222')
            ->assertJsonPath('data.url', fn ($u) => str_contains($u, '/s/r/'));
        $url = $this->postJson("/api/v1/payments/{$payment->uuid}/share")->json('data.url');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');

        Sanctum::actingAs($clerk);
        $this->getJson("/api/v1/payments/{$payment->uuid}/receipt.json")->assertOk()
            ->assertJsonPath('data.brand.name', 'Kisenyi Referral')
            ->assertJsonPath('data.method', 'mobile_money')
            ->assertJsonPath('data.reference', 'MM-1')
            ->assertJsonPath('data.totals.paid_this_payment', '10000.00')
            ->assertJsonPath('data.totals.total', '30000.00')
            ->assertJsonPath('data.balance', '20000.00')
            ->assertJsonPath('data.settled', false)
            ->assertJsonPath('data.qr_payload', "td:receipt:{$payment->uuid}:10000.00:UGX")
            ->assertJsonStructure(['data' => ['receipt_no', 'lines' => [['description', 'quantity', 'unit_price', 'line_total']], 'footer', 'paid_at', 'received_by']]);

        // Revoking through the receipt kills the invoice's links.
        $this->deleteJson("/api/v1/payments/{$payment->uuid}/share")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertStatus(410);

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('receptionist', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->getJson("/api/v1/payments/{$payment->uuid}/receipt.json")->assertStatus(404);
        $this->postJson("/api/v1/payments/{$payment->uuid}/share")->assertStatus(404);
    }

    public function test_the_cashier_day_adds_up_by_method_and_person_in_the_hospital_time_zone(): void
    {
        $this->h->update(['timezone' => 'Africa/Kampala']); // UTC+3
        $a = $this->staff('receptionist');
        $b = $this->staff('accountant');
        $invoice = $this->invoice('50000.00');
        $p1 = app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '20000.00', [], $a->id);
        $p2 = app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '5000.00', [], $b->id);
        $p3 = app(BillingService::class)->recordPayment($invoice, PaymentMethod::Bank, '10000.00', ['reference' => 'B1'], $b->id);

        // 22:30 UTC the day before is already "today" in Kampala; 20:30 UTC is still yesterday there.
        $today = '2026-10-16';
        DB::table('payments')->where('id', $p1->id)->update(['created_at' => '2026-10-15 21:30:00']); // 00:30 on the 16th, Kampala
        DB::table('payments')->where('id', $p2->id)->update(['created_at' => '2026-10-16 10:00:00']);
        DB::table('payments')->where('id', $p3->id)->update(['created_at' => '2026-10-15 20:30:00']); // 23:30 on the 15th, Kampala

        $foreign = $this->invoice('99000.00', $other = Hospital::factory()->create(['currency' => 'UGX']));
        $fp = app(BillingService::class)->recordPayment($foreign, PaymentMethod::Cash, '99000.00', [], null);
        DB::table('payments')->where('id', $fp->id)->update(['created_at' => '2026-10-16 10:00:00']);
        app(CurrentHospital::class)->set($this->h->id);

        $this->getJson('/api/v1/cashier/day-summary')->assertStatus(401);
        Sanctum::actingAs($this->staff('lab_technician'));
        $this->getJson("/api/v1/cashier/day-summary?date={$today}")->assertStatus(403);

        Sanctum::actingAs($b);
        $this->getJson('/api/v1/cashier/day-summary?date=16-10-2026')->assertStatus(422);
        $r = $this->getJson("/api/v1/cashier/day-summary?date={$today}")->assertOk()
            ->assertJsonPath('data.date', $today)
            ->assertJsonPath('data.currency', 'UGX')
            ->assertJsonPath('data.total', '25000.00')
            ->assertJsonPath('data.payments_count', 2)
            ->assertJsonPath('data.totals_by_method.0', ['method' => 'cash', 'count' => 2, 'amount' => '25000.00'])
            ->assertJsonPath('data.by_user.0.total', '20000.00')
            ->assertJsonStructure(['data' => ['invoices_raised', 'voided', 'by_user' => [['name', 'total']]]]);
        $this->assertCount(1, $r->json('data.totals_by_method'), 'the other hospital and the other day are not in it');

        $this->get("/api/v1/cashier/day-summary/pdf?date={$today}")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_debtors_age_by_the_shared_outstanding_definition(): void
    {
        $fresh = $this->invoice('1000.00');
        $mid = $this->invoice('2000.00');
        $old = $this->invoice('4000.00');
        $paid = $this->invoice('8000.00');
        app(BillingService::class)->recordPayment($paid, PaymentMethod::Cash, '8000.00', [], null);
        $part = $this->invoice('16000.00');
        app(BillingService::class)->recordPayment($part, PaymentMethod::Cash, '6000.00', [], null);
        DB::table('invoices')->where('id', $mid->id)->update(['created_at' => now()->subDays(45)]);
        DB::table('invoices')->where('id', $old->id)->update(['created_at' => now()->subDays(120)]);
        DB::table('invoices')->where('id', $part->id)->update(['created_at' => now()->subDays(70)]);

        $this->getJson('/api/v1/debtors')->assertStatus(401);
        Sanctum::actingAs($this->staff('lab_technician'));
        $this->getJson('/api/v1/debtors')->assertStatus(403);

        Sanctum::actingAs($this->staff('accountant'));
        $all = $this->getJson('/api/v1/debtors')->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.total_outstanding', '17000.00')
            ->assertJsonPath('meta.aging', ['0-30' => '1000.00', '31-60' => '2000.00', '61-90' => '10000.00', '90+' => '4000.00'])
            ->assertJsonPath('data.0.uuid', $old->uuid)
            ->assertJsonPath('data.0.bucket', '90+')
            ->assertJsonPath('data.0.age_days', 120)
            ->assertJsonStructure(['data' => [['uuid', 'number', 'balance', 'age_days', 'bucket', 'patient' => ['uuid', 'name', 'patient_no', 'phone']]]]);
        $this->assertNotContains($paid->uuid, array_column($all->json('data'), 'uuid'));

        $this->getJson('/api/v1/debtors?bucket=61-90')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.balance', '10000.00');
        $this->getJson('/api/v1/debtors?bucket=90%2B')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $old->uuid);
        $this->getJson('/api/v1/debtors?bucket=90+')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/debtors?bucket=nonsense')->assertStatus(422);
        $this->getJson('/api/v1/debtors?q='.$fresh->invoice_no)->assertOk()->assertJsonPath('meta.total', 1);

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('accountant', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->getJson('/api/v1/debtors')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.total_outstanding', '0.00');
    }

    public function test_money_endpoints_require_an_idempotency_key_from_the_app_only(): void
    {
        $invoice = $this->invoice();
        $item = StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '5.00']);
        Sanctum::actingAs($this->staff('accountant'));
        $pay = ['method' => 'cash', 'amount' => 1000];

        // The web / Field Mode send no X-App-Version: unchanged.
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", $pay)->assertCreated();

        // The app must say which attempt this is.
        $app = ['X-App-Version' => '1.4.0'];
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", $pay, $app)
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.Idempotency-Key.0', 'The Idempotency-Key header is required.');
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", $pay, $app + ['Idempotency-Key' => 'pay-1'])->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", $pay, $app + ['Idempotency-Key' => 'pay-1'])
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->count(), 'the replay did not charge again');

        foreach (['receive' => ['quantity' => 1], 'adjust' => ['reason' => 'count_correction', 'quantity' => 1], 'write-off' => ['reason' => 'expired', 'quantity' => 1, 'note' => 'x']] as $path => $body) {
            Sanctum::actingAs($this->staff('pharmacist'));
            $this->postJson("/api/v1/stock-items/{$item->uuid}/{$path}", $body, $app)->assertStatus(422)->assertJsonPath('errors.Idempotency-Key.0', 'The Idempotency-Key header is required.');
        }

        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $this->h->id])->id, 'status' => 'ongoing']);
        $this->postJson("/api/v1/visits/{$visit->uuid}/dispensations", ['items' => [['stock_item_id' => $item->id, 'quantity' => 1]]], $app)->assertStatus(422);

        // Reads and other writes are not affected.
        Sanctum::actingAs($this->staff('accountant'));
        $this->getJson('/api/v1/debtors', $app)->assertOk();
    }
}
