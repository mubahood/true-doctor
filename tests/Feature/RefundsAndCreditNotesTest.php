<?php

namespace Tests\Feature;

use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Credits\Index as CreditsIndex;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\JournalEntry;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\CashierDaySummary;
use App\Services\Finance\BooksBackfill;
use App\Services\Finance\ChartOfAccounts;
use App\Services\InvoiceCreditService;
use App\Support\CurrentHospital;
use Database\Seeders\DemoScenarios\Refunds;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Refunds and credit notes (roadmap 04 §8, docs/refunds.md): the rules, the
 * approval, the books, the cashier's day, the screens and the API.
 */
class RefundsAndCreditNotesTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private InvoiceCreditService $credits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();
        $this->h = $this->hospital();
        $this->credits = app(InvoiceCreditService::class);
    }

    private function hospital(array $billing = []): Hospital
    {
        $h = Hospital::factory()->create(['currency' => 'UGX', 'settings' => ['billing' => $billing]]);
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

    /** @return array<string,array{debit:string,credit:string}> system key or code => sums */
    private function entryFor(InvoiceCredit $credit): array
    {
        $entry = JournalEntry::query()->where('source_type', $credit->getMorphClass())->where('source_id', $credit->id)->firstOrFail();
        $out = [];
        foreach ($entry->lines()->with('account')->get() as $l) {
            $k = $l->account->system_key ?? $l->account->code;
            $out[$k] = ['debit' => (string) $l->debit, 'credit' => (string) $l->credit];
        }

        return $out;
    }

    // ── Credit notes ─────────────────────────────────────────────────────

    public function test_an_approver_credits_an_invoice_at_once_and_it_is_numbered_and_booked(): void
    {
        $invoice = $this->invoice();
        $credit = $this->credits->requestCreditNote($invoice, '20000', 'X-ray not done.', $this->staff('accountant'));

        $this->assertSame(CreditStatus::Approved, $credit->status);
        $this->assertMatchesRegularExpression('/^CN-\d{4}-00001$/', $credit->number);
        $invoice->refresh();
        $this->assertSame('20000.00', (string) $invoice->credited);
        $this->assertSame('30000.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);

        $lines = $this->entryFor($credit);
        $this->assertSame('20000.00', $lines['credit_notes']['debit']);
        $this->assertSame('20000.00', $lines['receivable']['credit']);
    }

    public function test_the_tax_goes_back_in_the_share_it_was_charged(): void
    {
        $h = $this->hospital(['tax_enabled' => true, 'tax_rate' => 10]);
        $invoice = $this->invoice(['100.00'], $h);
        $this->assertSame('110.00', (string) $invoice->total);

        $credit = $this->credits->requestCreditNote($invoice, '55.00', 'Half not done.', $this->staff('accountant', $h));

        $this->assertSame('5.00', (string) $credit->tax_part);
        $lines = $this->entryFor($credit);
        $this->assertSame('50.00', $lines['credit_notes']['debit']);
        $this->assertSame('5.00', $lines['tax.output']['debit']);
        $this->assertSame('55.00', $lines['receivable']['credit']);
    }

    public function test_crediting_in_pieces_gives_back_all_the_tax(): void
    {
        $h = $this->hospital(['tax_enabled' => true, 'tax_rate' => 10]);
        $invoice = $this->invoice(['100.00'], $h);
        $by = $this->staff('accountant', $h);

        // 36.67 × 10 / 110 = 3.3336… → 3.33 each time: three shares truncated
        // would leave 0.01 of tax payable on an invoice credited to nothing.
        foreach (['36.67', '36.67', '36.66'] as $amount) {
            $this->credits->requestCreditNote($invoice->fresh(), $amount, 'Not done.', $by);
        }

        $parts = InvoiceCredit::query()->where('invoice_id', $invoice->id)->pluck('tax_part')->map(fn ($p) => (string) $p)->all();
        $this->assertSame(['3.33', '3.33', '3.34'], $parts);
        $this->assertSame('110.00', (string) $invoice->fresh()->credited);
    }

    public function test_the_front_desk_asks_and_nothing_moves_until_it_is_approved(): void
    {
        $invoice = $this->invoice();
        $credit = $this->credits->requestCreditNote($invoice, '10000', 'Charged twice.', $this->staff('receptionist'));

        $this->assertSame(CreditStatus::Pending, $credit->status);
        $this->assertNull($credit->number);
        $this->assertSame('0.00', (string) $invoice->fresh()->credited);
        $this->assertSame(0, JournalEntry::query()->where('source_type', $credit->getMorphClass())->count());

        $this->expectException(RuntimeException::class);
        $this->credits->approve($credit, $this->staff('receptionist'));
    }

    public function test_approval_moves_the_money_once_and_a_decided_request_cannot_be_decided_again(): void
    {
        $invoice = $this->invoice();
        $credit = $this->credits->requestCreditNote($invoice, '10000', 'Charged twice.', $this->staff('receptionist'));
        $boss = $this->staff('hospital_admin');

        $approved = $this->credits->approve($credit, $boss);
        $this->assertSame(CreditStatus::Approved, $approved->status);
        $this->assertSame($boss->id, $approved->decided_by);
        $this->assertSame('40000.00', (string) $invoice->fresh()->balance);

        try {
            $this->credits->approve($credit, $boss);
            $this->fail('approved twice');
        } catch (RuntimeException) {
        }
        $this->assertSame('10000.00', (string) $invoice->fresh()->credited);
    }

    public function test_a_rejected_request_moves_nothing_and_keeps_its_reason(): void
    {
        $invoice = $this->invoice();
        $credit = $this->credits->requestCreditNote($invoice, '10000', 'Charged twice.', $this->staff('receptionist'));

        $rejected = $this->credits->reject($credit, 'It was done.', $this->staff('accountant'));

        $this->assertSame(CreditStatus::Rejected, $rejected->status);
        $this->assertSame('It was done.', $rejected->decision_note);
        $this->assertSame('0.00', (string) $invoice->fresh()->credited);
        $this->expectException(RuntimeException::class);
        $this->credits->approve($credit, $this->staff('accountant'));
    }

    public function test_nothing_can_be_credited_beyond_the_invoice_counting_what_is_waiting(): void
    {
        $invoice = $this->invoice();
        $this->credits->requestCreditNote($invoice, '30000', 'Not done.', $this->staff('receptionist'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('can be credited');
        $this->credits->requestCreditNote($invoice, '20000.01', 'Not done either.', $this->staff('receptionist'));
    }

    public function test_crediting_what_is_left_settles_the_invoice(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '30000.00');
        $this->credits->requestCreditNote($invoice, '20000', 'Second test not done.', $this->staff('accountant'));

        $invoice->refresh();
        $this->assertSame('0.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
    }

    public function test_a_payment_after_a_credit_note_reads_the_credited_balance(): void
    {
        $invoice = $this->invoice();
        $this->credits->requestCreditNote($invoice, '10000', 'Charged twice.', $this->staff('accountant'));

        $this->pay($invoice, '40000.00');

        $this->assertSame('0.00', (string) $invoice->fresh()->balance);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    // ── Refunds ──────────────────────────────────────────────────────────

    public function test_a_refund_needs_money_paid_beyond_what_the_invoice_asks(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credit it first');
        $this->credits->requestRefund($invoice, PaymentMethod::Cash, '1000', 'Wants money back.', $this->staff('accountant'));
    }

    public function test_credit_then_refund_hands_the_cash_back_and_balances_the_books(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $accounts = $this->staff('accountant');
        $this->credits->requestCreditNote($invoice, '20000', 'Scan not done.', $accounts);
        $this->assertSame('20000.00', $invoice->fresh()->refundable());

        try {
            $this->credits->requestRefund($invoice, PaymentMethod::Cash, '20000.01', 'Too much.', $accounts);
            $this->fail('over-refunded');
        } catch (RuntimeException) {
        }

        $refund = $this->credits->requestRefund($invoice, PaymentMethod::Cash, '20000', 'Cash back for the scan.', $accounts);

        $this->assertMatchesRegularExpression('/^RF-\d{4}-00001$/', $refund->number);
        $invoice->refresh();
        $this->assertSame('20000.00', (string) $invoice->refunded);
        $this->assertSame('0.00', $invoice->refundable());
        $this->assertSame('0.00', (string) $invoice->balance);

        $lines = $this->entryFor($refund);
        $this->assertSame('20000.00', $lines['receivable']['debit']);
        $this->assertSame('20000.00', $lines['book.cash']['credit']);

        // Receivables are back to nothing for this invoice: 50000 billed, 30000 kept.
        $receivable = app(ChartOfAccounts::class)->key('receivable');
        $sum = \App\Models\JournalLine::query()->where('ledger_account_id', $receivable->id)->selectRaw('SUM(debit) - SUM(credit) AS b')->value('b');
        $this->assertSame(0, bccomp((string) $sum, '0', 2));
    }

    public function test_a_refund_can_go_back_on_the_patients_card_and_is_booked_once(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $accounts = $this->staff('accountant');
        $card = app(CardService::class)->issue(Patient::find($invoice->patient_id), [], $accounts->id);
        $this->credits->requestCreditNote($invoice, '15000', 'Drug returned unopened.', $accounts);

        $refund = $this->credits->requestRefund($invoice, PaymentMethod::Card, '15000', 'Back on the card.', $accounts, null, $card);

        $this->assertNotNull($refund->card_record_id);
        $this->assertSame('15000.00', (string) $card->fresh()->balance);
        $this->assertSame('15000.00', $this->entryFor($refund)['card_deposits']['credit']);
        $this->assertSame(0, app(BooksBackfill::class)->pending()['card_records'], 'the card movement is not booked a second time');
    }

    public function test_mobile_money_refunds_need_a_reference(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $accounts = $this->staff('accountant');
        $this->credits->requestCreditNote($invoice, '5000', 'Overcharged.', $accounts);

        $this->expectException(RuntimeException::class);
        $this->credits->requestRefund($invoice, PaymentMethod::MobileMoney, '5000', 'Back by MoMo.', $accounts);
    }

    public function test_a_closed_year_refuses_approval(): void
    {
        $invoice = $this->invoice();
        $credit = $this->credits->requestCreditNote($invoice, '10000', 'Charged twice.', $this->staff('receptionist'));
        FinancialYear::query()->create(['name' => 'This year', 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(), 'status' => 'closed']);

        $this->expectException(RuntimeException::class);
        $this->credits->approve($credit, $this->staff('accountant'));
    }

    public function test_a_decided_document_is_never_edited_or_deleted(): void
    {
        $credit = $this->credits->requestCreditNote($this->invoice(), '10000', 'Charged twice.', $this->staff('accountant'));

        try {
            $credit->update(['amount' => '1.00']);
            $this->fail('edited');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $credit->delete();
    }

    public function test_the_cashiers_day_shows_refunds_and_the_net(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        $accounts = $this->staff('accountant');
        $this->credits->requestCreditNote($invoice, '20000', 'Scan not done.', $accounts);
        $this->credits->requestRefund($invoice, PaymentMethod::Cash, '20000', 'Cash back.', $accounts);

        $day = app(CashierDaySummary::class)->forDate($this->h, app(CashierDaySummary::class)->today($this->h));

        $this->assertSame('50000.00', $day['total']);
        $this->assertSame('20000.00', $day['refunds']['total']);
        $this->assertSame(1, $day['credit_notes']['count']);
        $this->assertSame('30000.00', $day['net']);
    }

    // ── Screens ──────────────────────────────────────────────────────────

    public function test_the_invoice_page_asks_and_the_approver_approves_or_rejects(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->staff('receptionist'));

        Livewire::test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->call('openCredit', 'credit_note')
            ->set('credit.amount', '')
            ->call('saveCredit')
            ->assertHasErrors(['credit.amount', 'credit.reason'])
            ->set('credit.amount', '10000')
            ->set('credit.reason', 'Charged twice.')
            ->call('saveCredit')
            ->assertHasNoErrors()
            ->assertSee('Waiting for approval')
            ->assertDontSee('Approve credit note');

        $pending = InvoiceCredit::query()->firstOrFail();
        $this->assertSame(CreditStatus::Pending, $pending->status);

        $this->actingAs($this->staff('accountant'));
        Livewire::test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->call('approveCredit', $pending->id)
            ->assertSee($pending->fresh()->number)
            ->assertSee('Credit notes');
        $this->assertSame('10000.00', (string) $invoice->fresh()->credited);

        $second = $this->credits->requestCreditNote($invoice, '5000', 'Another.', $this->staff('receptionist'));
        Livewire::test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->call('openReject', $second->id)
            ->set('rejectNote', 'No.')
            ->call('rejectCredit')
            ->assertHasNoErrors();
        $this->assertSame(CreditStatus::Rejected, $second->fresh()->status);
    }

    public function test_the_refund_dialog_refuses_what_cannot_be_refunded(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->staff('accountant'));

        Livewire::test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->call('openCredit', 'refund')
            ->set('credit.amount', '1000')
            ->set('credit.reason', 'Money back.')
            ->call('saveCredit')
            ->assertHasErrors(['credit.amount']);
        $this->assertSame(0, InvoiceCredit::query()->count());
    }

    public function test_a_role_without_billing_cannot_ask_and_a_cashier_cannot_approve(): void
    {
        $invoice = $this->invoice();
        $pending = $this->credits->requestCreditNote($invoice, '5000', 'Charged twice.', $this->staff('receptionist'));

        $this->actingAs($this->staff('nurse'));
        Livewire::test(InvoiceShow::class, ['invoice' => $invoice->uuid])->assertForbidden();
        $this->get(route('admin.credits.index'))->assertForbidden();

        $this->actingAs($this->staff('doctor'));
        Livewire::test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->call('approveCredit', $pending->id)
            ->assertForbidden();
        $this->assertSame(CreditStatus::Pending, $pending->fresh()->status);
    }

    public function test_the_list_shows_what_is_waiting_and_approves_it(): void
    {
        $invoice = $this->invoice();
        $pending = $this->credits->requestCreditNote($invoice, '5000', 'Charged twice.', $this->staff('receptionist'));
        $this->actingAs($this->staff('accountant'));

        $this->get(route('admin.credits.index'))->assertOk()->assertSee('Refunds and credit notes');

        Livewire::test(CreditsIndex::class)
            ->assertSee('1 waiting for approval')
            ->call('peek', $pending->id)
            ->assertSee('Charged twice.')
            ->call('approve', $pending->id);

        $this->assertSame(CreditStatus::Approved, $pending->fresh()->status);
    }

    public function test_another_hospitals_documents_are_out_of_reach(): void
    {
        $other = $this->hospital();
        $theirs = $this->credits->requestCreditNote($this->invoice(['10000.00'], $other), '5000', 'Theirs.', $this->staff('receptionist', $other));

        app(CurrentHospital::class)->set($this->h->id);
        $this->actingAs($this->staff('accountant'));

        Livewire::test(CreditsIndex::class)->assertDontSee('Theirs.');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(CreditsIndex::class)->call('approve', $theirs->id);
    }

    public function test_the_pdf_opens_for_an_approved_document_only(): void
    {
        $invoice = $this->invoice();
        $approved = $this->credits->requestCreditNote($invoice, '5000', 'Charged twice.', $this->staff('accountant'));
        $pending = $this->credits->requestCreditNote($invoice, '5000', 'Again.', $this->staff('receptionist'));
        $this->actingAs($this->staff('accountant'));

        $this->get(route('admin.credits.pdf', $approved))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('admin.credits.pdf', $pending))->assertNotFound();
    }

    // ── API ──────────────────────────────────────────────────────────────

    public function test_the_api_credits_refunds_and_reports_the_figures(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        Sanctum::actingAs($this->staff('accountant'));

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/refunds", ['amount' => 1000, 'method' => 'cash', 'reason' => 'Back.'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/credit-notes", ['amount' => 20000, 'reason' => 'Scan not done.'])
            ->assertCreated()
            ->assertJsonPath('data.credit.kind', 'credit_note')
            ->assertJsonPath('data.credit.status', 'approved')
            ->assertJsonPath('data.figures.refundable', '20000.00');

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/refunds", ['amount' => 20000, 'method' => 'cash', 'reason' => 'Back.'], ['Idempotency-Key' => 'rf-1'])
            ->assertCreated()->assertJsonPath('data.figures.refunded', '20000.00');
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/refunds", ['amount' => 20000, 'method' => 'cash', 'reason' => 'Back.'], ['Idempotency-Key' => 'rf-1'])
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, InvoiceCredit::query()->where('kind', CreditKind::Refund->value)->count(), 'the replay did not pay twice');

        $this->getJson("/api/v1/invoices/{$invoice->uuid}/credits")->assertOk()
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonCount(2, 'data.credits');
        $this->getJson("/api/v1/invoices/{$invoice->uuid}")->assertOk()
            ->assertJsonPath('data.credited', '20000.00')
            ->assertJsonPath('data.refunded', '20000.00');

        $pdf = InvoiceCredit::query()->where('kind', 'refund')->firstOrFail();
        $this->get("/api/v1/credits/{$pdf->uuid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_app_must_send_an_idempotency_key_to_refund_or_credit(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '50000.00');
        Sanctum::actingAs($this->staff('accountant'));
        $app = ['X-App-Version' => '9.9.9'];

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/credit-notes", ['amount' => 20000, 'reason' => 'Scan not done.'], $app)
            ->assertStatus(422)->assertJsonPath('errors.Idempotency-Key.0', 'The Idempotency-Key header is required.');
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/refunds", ['amount' => 1000, 'method' => 'cash', 'reason' => 'Back.'], $app)
            ->assertStatus(422)->assertJsonPath('errors.Idempotency-Key.0', 'The Idempotency-Key header is required.');
        $this->assertSame(0, InvoiceCredit::query()->count());
    }

    public function test_the_api_approves_and_rejects_with_permission_only(): void
    {
        $invoice = $this->invoice();
        Sanctum::actingAs($this->staff('receptionist'));
        $one = $this->postJson("/api/v1/invoices/{$invoice->uuid}/credit-notes", ['amount' => 5000, 'reason' => 'Twice.'])
            ->assertCreated()->assertJsonPath('data.credit.status', 'pending')->json('data.credit.uuid');
        $two = $this->postJson("/api/v1/invoices/{$invoice->uuid}/credit-notes", ['amount' => 5000, 'reason' => 'Again.'])->json('data.credit.uuid');
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/credit-notes", ['amount' => 5000])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/credits/{$one}/approve")->assertForbidden()->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($this->staff('accountant'));
        $this->getJson('/api/v1/credits?status=pending')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.pending', 2);
        $this->postJson("/api/v1/credits/{$one}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/credits/{$one}/approve")->assertStatus(422);
        $this->postJson("/api/v1/credits/{$two}/reject", ['note' => 'Was done.'])->assertOk()->assertJsonPath('data.status', 'rejected');

        Sanctum::actingAs($this->staff('nurse'));
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/credit-notes", ['amount' => 5000, 'reason' => 'x x x'])->assertForbidden();
    }

    public function test_the_api_hides_another_hospital_and_needs_a_token(): void
    {
        $other = $this->hospital();
        $theirInvoice = $this->invoice(['10000.00'], $other);
        $theirs = $this->credits->requestCreditNote($theirInvoice, '5000', 'Theirs.', $this->staff('receptionist', $other));

        $this->postJson("/api/v1/invoices/{$theirInvoice->uuid}/credit-notes", ['amount' => 1, 'reason' => 'abc'])->assertUnauthorized();

        app(CurrentHospital::class)->set($this->h->id);
        Sanctum::actingAs($this->staff('accountant'));
        $this->postJson("/api/v1/invoices/{$theirInvoice->uuid}/credit-notes", ['amount' => 1, 'reason' => 'abc'])->assertNotFound();
        $this->postJson("/api/v1/credits/{$theirs->uuid}/approve")->assertNotFound();
        $this->getJson('/api/v1/credits')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ── Demo ─────────────────────────────────────────────────────────────

    public function test_the_demo_scenario_is_consistent_and_safe_to_run_twice(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);
        $hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();

        Refunds::run($hospital);
        app(CurrentHospital::class)->set($hospital->id);
        $count = InvoiceCredit::query()->count();
        $this->assertGreaterThan(0, $count);
        $this->assertGreaterThan(0, InvoiceCredit::query()->where('status', CreditStatus::Approved->value)->count());

        Refunds::run($hospital);
        $this->assertSame($count, InvoiceCredit::query()->count());

        foreach (InvoiceCredit::query()->with('invoice')->get() as $c) {
            $this->assertSame($c->invoice->patient_id, $c->patient_id);
            $inv = $c->invoice;
            $owed = bcsub($inv->netTotal(), $inv->held(), 2);
            $this->assertSame(bccomp($owed, '0', 2) > 0 ? $owed : '0.00', (string) $inv->balance, 'the balance agrees with its parts');
        }
    }
}
