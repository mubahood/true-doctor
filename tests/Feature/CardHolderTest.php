<?php

namespace Tests\Feature;

use App\Enums\CardHolderRelationship;
use App\Enums\CardHolderStatus;
use App\Enums\PaymentMethod;
use App\Models\CardRecord;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * One card, a family (docs/cards.md).
 *
 * `patient_cards.patient_id` is the primary holder and never changes; everyone
 * else is a `card_holders` row. The rule the whole system leans on is
 * `PatientCard::covers()` — until it existed, BillingService compared patient
 * ids by hand and a mother's card could not pay for her own child.
 */
class CardHolderTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private CardService $cards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cards = app(CardService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function patient(?Hospital $hospital = null): Patient
    {
        return Patient::factory()->create(['hospital_id' => ($hospital ?? $this->hospital)->id]);
    }

    // ── Who is on the card ───────────────────────────────────────────────

    public function test_the_person_it_was_issued_to_is_always_on_it(): void
    {
        $owner = $this->patient();
        $card = $this->cards->issue($owner, []);

        $this->assertTrue($card->covers($owner));
        $this->assertSame(0, $card->holders()->count(), 'the primary holder is the card, not a row on it');
    }

    public function test_a_family_member_is_added_and_may_then_spend(): void
    {
        $mother = $this->patient();
        $child = $this->patient();
        $card = $this->cards->issue($mother, []);

        $this->assertFalse($card->covers($child));

        $holder = $this->cards->addHolder($card, $child, CardHolderRelationship::Child);

        $this->assertSame(CardHolderStatus::Active, $holder->status);
        $this->assertTrue($card->fresh()->covers($child));
    }

    public function test_a_revoked_holder_stops_spending_but_keeps_their_history(): void
    {
        $mother = $this->patient();
        $child = $this->patient();
        $card = $this->cards->issue($mother, []);
        $this->cards->credit($card, '100.00', null);

        $holder = $this->cards->addHolder($card, $child, CardHolderRelationship::Child);
        $this->cards->debit($card, '40.00', 'Consultation', null, ['for' => $child->id]);

        $this->cards->revokeHolder($holder);

        $this->assertFalse($card->fresh()->covers($child));
        $this->assertSame(
            1,
            CardRecord::where('patient_card_id', $card->id)->where('patient_id', $child->id)->count(),
            'revoking a holder erased what they spent',
        );
    }

    /** Re-adding somebody is the same row turned back on, never a second one. */
    public function test_adding_a_revoked_holder_again_flips_the_same_row(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $child = $this->patient();

        $first = $this->cards->addHolder($card, $child, CardHolderRelationship::Child);
        $this->cards->revokeHolder($first);
        $second = $this->cards->addHolder($card, $child, CardHolderRelationship::Ward);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $card->holders()->count());
        $this->assertSame(CardHolderRelationship::Ward, $second->relationship);
        $this->assertNull($second->revoked_at, 'the revocation stamp was left behind on a live holder');
    }

    public function test_the_primary_holder_cannot_be_added_to_their_own_card(): void
    {
        $owner = $this->patient();
        $card = $this->cards->issue($owner, []);

        $this->expectException(RuntimeException::class);
        $this->cards->addHolder($card, $owner, CardHolderRelationship::Other);
    }

    public function test_another_hospitals_patient_cannot_be_put_on_a_card(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $stranger = $this->patient(Hospital::factory()->create());

        $this->expectException(RuntimeException::class);
        $this->cards->addHolder($card, $stranger, CardHolderRelationship::Other);
    }

    // ── What the ledger records ──────────────────────────────────────────

    /**
     * The `patient_id` on a ledger row is who the money was spent ON. It used
     * to be a copy of the card's owner, which on a family card says nothing and
     * makes a per-member usage report impossible.
     */
    public function test_a_ledger_row_records_the_member_the_money_was_spent_on(): void
    {
        $mother = $this->patient();
        $child = $this->patient();
        $card = $this->cards->issue($mother, []);
        $this->cards->credit($card, '100.00', null);
        $this->cards->addHolder($card, $child, CardHolderRelationship::Child);

        $own = $this->cards->debit($card, '10.00', 'Hers');
        $theirs = $this->cards->debit($card, '20.00', 'His', null, ['for' => $child]);

        $this->assertSame($mother->id, $own->patient_id, 'a charge with no member named is the cardholder’s own');
        $this->assertSame($child->id, $theirs->patient_id);
    }

    public function test_a_charge_cannot_be_attributed_to_somebody_not_on_the_card(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $this->cards->credit($card, '100.00', null);
        $stranger = $this->patient();

        $this->expectException(RuntimeException::class);
        $this->cards->debit($card, '10.00', 'Not theirs', null, ['for' => $stranger->id]);
    }

    // ── The counter ──────────────────────────────────────────────────────

    /** The bug this whole table exists to fix. */
    public function test_a_mothers_card_pays_her_childs_invoice(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $mother = $this->patient();
        $child = $this->patient();
        $card = $this->cards->issue($mother, []);
        $this->cards->credit($card, '500.00', 'Top-up');
        $this->cards->addHolder($card, $child, CardHolderRelationship::Child);

        $billing = app(BillingService::class);
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $child->id]);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '100.00']);
        $billing->orderService($visit, $service->id, 1);
        $invoice = $billing->generateInvoice($visit->fresh(), '0.00', null);

        $clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $payment = $billing->recordPayment($invoice, PaymentMethod::Card, '100.00', ['card' => $card->fresh()], $clerk->id);

        $this->assertSame('400.00', (string) $card->fresh()->balance);
        $this->assertSame($card->id, $payment->patient_card_id);
        $this->assertSame(
            $child->id,
            CardRecord::findOrFail($payment->card_record_id)->patient_id,
            'the debit was attributed to the cardholder rather than the patient treated',
        );
    }

    public function test_a_card_nobody_on_this_invoice_holds_is_refused(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $stranger = $this->patient();
        $card = $this->cards->issue($stranger, []);
        $this->cards->credit($card, '500.00', null);

        $billing = app(BillingService::class);
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $this->patient()->id]);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '100.00']);
        $billing->orderService($visit, $service->id, 1);
        $invoice = $billing->generateInvoice($visit->fresh(), '0.00', null);

        $this->expectException(RuntimeException::class);
        $billing->recordPayment($invoice, PaymentMethod::Card, '100.00', ['card' => $card]);
    }

    /** The counter has to be OFFERED the family card or it may as well not exist. */
    public function test_a_card_a_patient_is_on_is_offered_to_them(): void
    {
        $mother = $this->patient();
        $child = $this->patient();
        $card = $this->cards->issue($mother, []);
        $ownCard = $this->cards->issue($child, []);
        $holder = $this->cards->addHolder($card, $child, CardHolderRelationship::Child);

        $offered = \App\Models\PatientCard::spendableBy($child->id)->pluck('id')->sort()->values()->all();
        $this->assertSame([$card->id, $ownCard->id], $offered);

        $this->cards->revokeHolder($holder);

        $this->assertSame(
            [$ownCard->id],
            \App\Models\PatientCard::spendableBy($child->id)->pluck('id')->all(),
            'a revoked holder is still offered the card',
        );
    }
}
