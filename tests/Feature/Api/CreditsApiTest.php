<?php

namespace Tests\Feature\Api;

use App\Enums\CreditKind;
use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\InvoiceCreditService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the app's refunds and credit notes screens read and write
 * (docs/api/refunds.md): one document with its invoice, the waiting ones
 * first, the refund form's choices, and the guards that keep money from
 * going out twice. RefundsAndCreditNotesTest covers the rules themselves.
 */
class CreditsApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private InvoiceCreditService $credits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->h = $this->hospital();
        $this->credits = app(InvoiceCreditService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hospital(): Hospital
    {
        $h = Hospital::factory()->create(['currency' => 'UGX', 'settings' => ['billing' => []]]);
        app(CurrentHospital::class)->set($h->id);

        return $h;
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    /** @param list<string> $prices */
    private function invoice(array $prices = ['30000.00', '20000.00'], ?Hospital $h = null): Invoice
    {
        $h ??= $this->h;
        app(CurrentHospital::class)->set($h->id);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $h->id])->id, 'status' => 'ongoing']);
        foreach ($prices as $p) {
            app(BillingService::class)->orderService($visit->fresh(), Service::factory()->create(['hospital_id' => $h->id, 'price' => $p])->id, 1);
        }

        return app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        app(BillingService::class)->recordPayment($invoice->fresh(), PaymentMethod::Cash, $amount, [], null);
    }

    public function test_one_document_reads_with_its_invoice_lines_and_figures(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $asked = $this->credits->requestCreditNote($invoice, '20000', 'Scan not done.', $this->staff('receptionist'));

        Sanctum::actingAs($this->staff('accountant'));
        $res = $this->getJson("/api/v1/credits/{$asked->uuid}")->assertOk()
            ->assertJsonPath('data.credit.uuid', $asked->uuid)
            ->assertJsonPath('data.credit.status', 'pending')
            ->assertJsonPath('data.credit.number', null)
            ->assertJsonPath('data.credit.amount', '20000.00')
            ->assertJsonPath('data.credit.reason', 'Scan not done.')
            ->assertJsonPath('data.credit.can_approve', true)
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.invoice.uuid', $invoice->uuid)
            ->assertJsonPath('data.invoice.total', '50000.00')
            ->assertJsonPath('data.invoice.amount_paid', '50000.00')
            ->assertJsonCount(2, 'data.invoice.items')
            ->assertJsonPath('data.figures.creditable', '30000.00')
            ->assertJsonPath('data.figures.pending_credit', '20000.00')
            ->assertJsonPath('data.figures.can_refund', '20000.00');
        $this->assertEqualsCanonicalizing(['30000.00', '20000.00'], array_column($res->json('data.invoice.items'), 'line_total'));

        // The one who asked reads it too, and may not decide it.
        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson("/api/v1/credits/{$asked->uuid}")->assertOk()
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.credit.can_approve', false);
    }

    public function test_a_refund_onto_a_card_names_the_card_and_the_method(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $accounts = $this->staff('accountant');
        $card = app(CardService::class)->issue(Patient::find($invoice->patient_id), [], $accounts->id);
        $this->credits->requestCreditNote($invoice, '15000', 'Drug returned unopened.', $accounts);
        $refund = $this->credits->requestRefund($invoice, PaymentMethod::Card, '15000', 'Back on the card.', $accounts, null, $card);

        Sanctum::actingAs($accounts);
        $this->getJson("/api/v1/credits/{$refund->uuid}")->assertOk()
            ->assertJsonPath('data.credit.status', 'approved')
            ->assertJsonPath('data.credit.method', 'card')
            ->assertJsonPath('data.credit.method_label', 'Prepaid card')
            ->assertJsonPath('data.credit.card.uuid', $card->uuid)
            ->assertJsonPath('data.credit.card.masked', $card->masked())
            ->assertJsonPath('data.can_approve', false);
        $this->getJson('/api/v1/credits?kind=refund')->assertOk()->assertJsonPath('data.0.card.masked', $card->masked());
    }

    public function test_the_waiting_ones_come_first_when_asked(): void
    {
        $front = $this->staff('receptionist');
        Carbon::setTestNow('2026-10-10 09:00:00');
        $old = $this->credits->requestCreditNote($this->invoice(), '1000', 'Asked long ago.', $front);
        Carbon::setTestNow('2026-10-12 09:00:00');
        $approved = $this->credits->requestCreditNote($this->invoice(), '2000', 'Decided at once.', $this->staff('accountant'));
        Carbon::setTestNow('2026-10-13 09:00:00');
        $rejected = $this->credits->requestCreditNote($this->invoice(), '3000', 'Turned down.', $front);
        $this->credits->reject($rejected, 'It was done.', $this->staff('accountant'));
        Carbon::setTestNow();

        Sanctum::actingAs($this->staff('accountant'));
        $newest = $this->getJson('/api/v1/credits')->assertOk()->json('data.*.uuid');
        $this->assertSame([$rejected->uuid, $approved->uuid, $old->uuid], $newest);

        $this->getJson('/api/v1/credits?waiting_first=1')->assertOk()
            ->assertJsonPath('data.0.uuid', $old->uuid)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.1.uuid', $rejected->uuid)
            ->assertJsonPath('data.2.uuid', $approved->uuid)
            ->assertJsonPath('meta.pending', 1)
            ->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/credits?waiting_first=maybe')->assertStatus(422)->assertJsonValidationErrors(['waiting_first']);
    }

    public function test_the_request_form_gets_its_methods_and_the_patients_cards(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $card = app(CardService::class)->issue(Patient::find($invoice->patient_id), [], $this->staff('accountant')->id);

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson("/api/v1/invoices/{$invoice->uuid}/credits")->assertOk()
            ->assertJsonPath('data.can_request', true)
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.refund_method_options.0', ['value' => 'cash', 'label' => 'Cash', 'needs_reference' => false])
            ->assertJsonPath('data.refund_method_options.1', ['value' => 'mobile_money', 'label' => 'Mobile money', 'needs_reference' => true])
            ->assertJsonPath('data.refund_method_options.2.needs_reference', true)
            ->assertJsonPath('data.refund_method_options.3.value', 'card')
            ->assertJsonPath('data.cards.0', ['uuid' => $card->uuid, 'masked' => $card->masked(), 'balance' => '0.00'])
            ->assertJsonPath('data.figures.creditable', '50000.00')
            ->assertJsonPath('data.figures.can_refund', '0.00');

        // Someone who reads invoices but may not ask is not shown the cards.
        $reader = $this->staff('nurse');
        $reader->givePermissionTo('billing.view');
        Sanctum::actingAs($reader);
        $this->getJson("/api/v1/invoices/{$invoice->uuid}/credits")->assertOk()
            ->assertJsonPath('data.can_request', false)
            ->assertJsonPath('data.cards', []);
    }

    public function test_money_goes_out_once_however_often_the_request_is_sent(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $front = $this->staff('receptionist');
        Sanctum::actingAs($front);

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/credit-notes", ['amount' => '20000', 'reason' => 'Scan not done.'], ['Idempotency-Key' => 'cn-1'])
            ->assertCreated()->assertJsonPath('data.credit.status', 'pending');

        // A refund on the strength of the waiting credit note, then the same
        // request again after a lost reply: one document.
        $first = $this->postJson("/api/v1/invoices/{$invoice->uuid}/refunds", ['amount' => '20000', 'method' => 'cash', 'reason' => 'Cash back.'], ['Idempotency-Key' => 'rf-1'])
            ->assertCreated()->assertJsonPath('data.credit.status', 'pending')->assertJsonPath('data.figures.can_refund', '0.00');
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/refunds", ['amount' => '20000', 'method' => 'cash', 'reason' => 'Cash back.'], ['Idempotency-Key' => 'rf-1'])
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.credit.uuid', $first->json('data.credit.uuid'));
        $this->assertSame(1, InvoiceCredit::query()->where('kind', CreditKind::Refund->value)->count(), 'the replay asked once');

        // A second refund (a new form, a new key) is refused: the first is already waiting.
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/refunds", ['amount' => '1', 'method' => 'cash', 'reason' => 'Again.'], ['Idempotency-Key' => 'rf-2'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.amount.0', 'Nothing has been paid beyond what this invoice asks for. Credit it first, then refund.');
        $this->assertSame(1, InvoiceCredit::query()->where('kind', CreditKind::Refund->value)->count());

        // Approved in order; approving the refund twice pays it once.
        Sanctum::actingAs($this->staff('accountant'));
        $note = InvoiceCredit::query()->where('kind', CreditKind::CreditNote->value)->firstOrFail();
        $this->postJson("/api/v1/credits/{$note->uuid}/approve", [], ['Idempotency-Key' => 'ap-cn'])->assertOk()->assertJsonPath('data.status', 'approved');
        $refund = $first->json('data.credit.uuid');
        $this->postJson("/api/v1/credits/{$refund}/approve", [], ['Idempotency-Key' => 'ap-rf'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/credits/{$refund}/approve", [], ['Idempotency-Key' => 'ap-rf'])->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->postJson("/api/v1/credits/{$refund}/approve", [], ['Idempotency-Key' => 'ap-rf-again'])
            ->assertStatus(422)->assertJsonPath('message', 'This has already been approved.');

        $invoice->refresh();
        $this->assertSame('20000.00', (string) $invoice->refunded, 'refunded once');
        $this->assertSame('0.00', $invoice->refundable());
        $this->getJson("/api/v1/invoices/{$invoice->uuid}/credits")->assertOk()
            ->assertJsonPath('data.figures.can_refund', '0.00')
            ->assertJsonPath('data.figures.refunded', '20000.00');
    }

    public function test_a_reason_is_needed_to_reject_and_a_decided_one_stays_decided(): void
    {
        $asked = $this->credits->requestCreditNote($this->invoice(), '5000', 'Twice.', $this->staff('receptionist'));
        Sanctum::actingAs($this->staff('accountant'));

        $this->postJson("/api/v1/credits/{$asked->uuid}/reject", ['note' => 'no'])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->postJson("/api/v1/credits/{$asked->uuid}/reject", ['note' => 'It was done.'], ['Idempotency-Key' => 'rj-1'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'It was done.');
        $this->postJson("/api/v1/credits/{$asked->uuid}/reject", ['note' => 'It was done.'], ['Idempotency-Key' => 'rj-1'])
            ->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->postJson("/api/v1/credits/{$asked->uuid}/approve")->assertStatus(422)->assertJsonPath('message', 'This has already been rejected.');
        $this->getJson("/api/v1/credits/{$asked->uuid}")->assertOk()->assertJsonPath('data.credit.status', 'rejected')->assertJsonPath('data.can_approve', false);
    }

    public function test_one_document_is_for_billing_and_for_its_own_hospital(): void
    {
        $asked = $this->credits->requestCreditNote($this->invoice(), '5000', 'Twice.', $this->staff('receptionist'));
        $this->getJson("/api/v1/credits/{$asked->uuid}")->assertUnauthorized();

        Sanctum::actingAs($this->staff('nurse'));
        $this->getJson("/api/v1/credits/{$asked->uuid}")->assertForbidden()->assertJsonPath('code', 'forbidden');

        $other = $this->hospital();
        $theirs = $this->credits->requestCreditNote($this->invoice(['10000.00'], $other), '5000', 'Theirs.', $this->staff('receptionist', $other));

        app(CurrentHospital::class)->set($this->h->id);
        Sanctum::actingAs($this->staff('accountant'));
        $this->getJson("/api/v1/credits/{$theirs->uuid}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson("/api/v1/credits/{$asked->uuid}")->assertOk();
        $this->getJson('/api/v1/credits?waiting_first=1')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
