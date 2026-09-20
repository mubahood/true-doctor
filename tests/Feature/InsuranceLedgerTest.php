<?php

namespace Tests\Feature;

use App\Enums\CardHolderRelationship;
use App\Enums\InsuranceEntryType;
use App\Models\CardRecord;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\InsuranceTransaction;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Services\CardService;
use App\Services\InsuranceLedgerService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The insurer's float, and how it clears its members' cards (docs/cards.md).
 *
 * The rule under every test here: a settlement moves TWO ledgers or neither.
 * Money off the float and money onto the card are one transaction, linked to
 * each other, and pressing the button twice must never post twice.
 */
class InsuranceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private CardService $cards;

    private InsuranceLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cards = app(CardService::class);
        $this->ledger = app(InsuranceLedgerService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function insurer(array $overrides = []): InsuranceProvider
    {
        return InsuranceProvider::create(array_merge([
            'name' => 'Jubilee '.uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    /** A member card carrying a debt of $debt. */
    private function indebtedCard(InsuranceProvider $insurer, string $debt): PatientCard
    {
        $card = $this->cards->issue($this->patient(), [
            'insurance_provider_id' => $insurer->id,
            'member_no' => 'M-'.uniqid(),
            'accepts_credit' => true,
            'max_credit' => '1000000.00',
        ]);

        $this->cards->debit($card, $debt, 'Treatment');

        return $card->fresh();
    }

    // ── The card belongs to an insurer ───────────────────────────────────

    public function test_a_card_issued_against_an_insurer_takes_their_terms(): void
    {
        $insurer = $this->insurer(['default_credit_limit' => '750.00']);

        $card = $this->cards->issue($this->patient(), ['insurance_provider_id' => $insurer->id, 'member_no' => 'M-1']);

        $this->assertTrue($card->isInsurance());
        $this->assertTrue($card->accepts_credit, 'an insurance card that cannot go into debt is no use to anybody');
        $this->assertSame('750.00', (string) $card->max_credit);
        $this->assertSame('M-1', $card->member_no);
    }

    public function test_another_hospitals_insurer_cannot_be_put_on_a_card(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = InsuranceProvider::create(['name' => 'Not ours', 'is_active' => true]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(RuntimeException::class);
        $this->cards->issue($this->patient(), ['insurance_provider_id' => $theirs->id]);
    }

    // ── The float ────────────────────────────────────────────────────────

    public function test_a_deposit_raises_the_float_and_writes_a_line(): void
    {
        $insurer = $this->insurer();

        $entry = $this->ledger->deposit($insurer, '5000.00', 'RTGS-9912', 'Q3 float');

        $this->assertSame('5000.00', (string) $insurer->fresh()->float_balance);
        $this->assertSame(InsuranceEntryType::Deposit, $entry->type);
        $this->assertSame('5000.00', (string) $entry->balance_after);
        $this->assertSame('RTGS-9912', $entry->reference);
    }

    public function test_an_inactive_insurer_takes_no_money(): void
    {
        $insurer = $this->insurer(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->ledger->deposit($insurer, '100.00', null, null);
    }

    public function test_an_adjustment_corrects_the_float_and_must_say_why(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '1000.00', null, null);

        $this->ledger->adjust($insurer, '-250.00', 'Duplicated deposit reversed');

        $this->assertSame('750.00', (string) $insurer->fresh()->float_balance);

        $this->expectException(RuntimeException::class);
        $this->ledger->adjust($insurer, '-10.00', '   ');
    }

    public function test_an_adjustment_cannot_take_the_float_below_nothing(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '100.00', null, null);

        $this->expectException(RuntimeException::class);
        $this->ledger->adjust($insurer, '-500.00', 'Too much');
    }

    // ── Clearing a card ──────────────────────────────────────────────────

    public function test_a_settlement_moves_both_ledgers_and_links_them(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);
        $card = $this->indebtedCard($insurer, '1200.00');

        $this->assertSame('1200.00', $card->debt());

        $entry = $this->ledger->settleCard($card);

        $this->assertNotNull($entry);
        $this->assertSame(InsuranceEntryType::Settlement, $entry->type);
        $this->assertSame('1200.00', (string) $entry->amount);
        $this->assertSame('3800.00', (string) $insurer->fresh()->float_balance, 'the float did not pay for it');
        $this->assertSame('0.00', (string) $card->fresh()->balance, 'the card was not cleared');

        $credit = CardRecord::where('insurance_transaction_id', $entry->id)->firstOrFail();
        $this->assertSame('1200.00', (string) $credit->amount);
        $this->assertSame($card->id, $entry->patient_card_id, 'the settlement does not say which card it paid');
    }

    /** A float that runs out clears what it can and stops. */
    public function test_a_short_float_pays_what_it_can(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '500.00', null, null);
        $card = $this->indebtedCard($insurer, '1200.00');

        $entry = $this->ledger->settleCard($card);

        $this->assertSame('500.00', (string) $entry->amount);
        $this->assertSame('0.00', (string) $insurer->fresh()->float_balance);
        $this->assertSame('-700.00', (string) $card->fresh()->balance);
    }

    /**
     * Pressing it twice must not post twice. A card that owes nothing is a
     * no-op, not an error — the button is on a page somebody may reload.
     */
    public function test_settling_a_card_that_owes_nothing_posts_nothing(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);
        $card = $this->indebtedCard($insurer, '100.00');

        $this->ledger->settleCard($card);
        $again = $this->ledger->settleCard($card->fresh());

        $this->assertNull($again);
        $this->assertSame(
            2,
            InsuranceTransaction::where('insurance_provider_id', $insurer->id)->count(),
            'a second press posted a second settlement',
        );
        $this->assertSame('4900.00', (string) $insurer->fresh()->float_balance);
    }

    public function test_paying_more_than_a_card_owes_is_refused(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);
        $card = $this->indebtedCard($insurer, '100.00');

        $this->expectException(RuntimeException::class);
        $this->ledger->settleCard($card, '500.00');
    }

    public function test_a_card_with_no_insurer_cannot_be_settled(): void
    {
        $card = $this->cards->issue($this->patient(), ['accepts_credit' => true, 'max_credit' => '100.00']);
        $this->cards->debit($card, '50.00', 'Treatment');

        $this->expectException(RuntimeException::class);
        $this->ledger->settleCard($card->fresh());
    }

    // ── Clearing everybody ───────────────────────────────────────────────

    public function test_one_sweep_clears_every_member_in_debt(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);

        $a = $this->indebtedCard($insurer, '1000.00');
        $b = $this->indebtedCard($insurer, '2000.00');
        $square = $this->cards->issue($this->patient(), ['insurance_provider_id' => $insurer->id]);

        $result = $this->ledger->settleAll($insurer);

        $this->assertSame(2, $result['settled']);
        $this->assertSame('3000.00', $result['paid']);
        $this->assertSame('0.00', $result['unpaid']);
        $this->assertSame('2000.00', $result['float']);
        $this->assertSame('0.00', (string) $a->fresh()->balance);
        $this->assertSame('0.00', (string) $b->fresh()->balance);
        $this->assertSame(
            0,
            InsuranceTransaction::where('patient_card_id', $square->id)->count(),
            'a card that owed nothing was settled anyway',
        );
    }

    /** A sweep that cannot pay everybody says what it left behind. */
    public function test_a_sweep_reports_what_the_float_could_not_cover(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '1500.00', null, null);

        $this->indebtedCard($insurer, '1000.00');
        $this->indebtedCard($insurer, '2000.00');

        $result = $this->ledger->settleAll($insurer);

        $this->assertSame('1500.00', $result['paid']);
        $this->assertSame('1500.00', $result['unpaid']);
        $this->assertSame('0.00', $result['float']);
    }

    public function test_a_second_sweep_finds_nothing_left_to_do(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);
        $this->indebtedCard($insurer, '1000.00');

        $this->ledger->settleAll($insurer);
        $again = $this->ledger->settleAll($insurer->fresh());

        $this->assertSame(0, $again['settled']);
        $this->assertSame('4000.00', (string) $insurer->fresh()->float_balance);
    }

    public function test_one_insurers_float_never_pays_anothers_members(): void
    {
        $ours = $this->insurer();
        $theirs = $this->insurer();
        $this->ledger->deposit($ours, '5000.00', null, null);
        $theirCard = $this->indebtedCard($theirs, '400.00');

        $this->ledger->settleAll($ours);

        $this->assertSame('-400.00', (string) $theirCard->fresh()->balance);
        $this->assertSame('5000.00', (string) $ours->fresh()->float_balance);
    }

    // ── The ledgers prove themselves ─────────────────────────────────────

    public function test_both_balances_agree_with_their_own_ledgers_after_a_settlement(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);
        $this->ledger->adjust($insurer, '250.00', 'Bank interest');
        $card = $this->indebtedCard($insurer, '800.00');
        $this->ledger->settleCard($card);

        $this->assertTrue($this->ledger->reconcile($insurer->fresh())['agrees'], 'the float drifted from its ledger');
        $this->assertTrue($this->cards->reconcile($card->fresh())['agrees'], 'the card drifted from its ledger');
    }

    public function test_what_the_insurer_is_owed_across_every_card(): void
    {
        $insurer = $this->insurer();
        $this->indebtedCard($insurer, '300.00');
        $this->indebtedCard($insurer, '450.50');
        $this->cards->issue($this->patient(), ['insurance_provider_id' => $insurer->id]);

        $this->assertSame('750.50', $insurer->fresh()->outstanding());
    }

    // ── The usage report ─────────────────────────────────────────────────

    public function test_the_usage_report_totals_by_member_not_by_cardholder(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '10000.00', null, null);

        $employee = $this->patient();
        $spouse = $this->patient();
        $card = $this->cards->issue($employee, [
            'insurance_provider_id' => $insurer->id,
            'member_no' => 'M-77',
            'accepts_credit' => true,
            'max_credit' => '9000.00',
        ]);
        $this->cards->addHolder($card, $spouse, CardHolderRelationship::Spouse);

        $this->cards->debit($card, '300.00', 'Consultation');
        $this->cards->debit($card, '500.00', 'Scan', null, ['for' => $spouse]);
        $this->cards->debit($card, '200.00', 'Drugs', null, ['for' => $spouse]);

        $report = $this->ledger->usage($insurer->fresh(), Carbon::now()->subDay(), Carbon::now());

        $this->assertSame('1000.00', $report['spent']);
        $this->assertCount(2, $report['members']);

        // Biggest spender first.
        $this->assertSame($spouse->fullName(), $report['members'][0]['patient']);
        $this->assertSame('700.00', $report['members'][0]['spent']);
        $this->assertSame(2, $report['members'][0]['entries']);
        $this->assertSame('M-77', $report['members'][0]['member_no']);
        $this->assertSame($employee->fullName(), $report['members'][1]['patient']);
    }

    /**
     * A settlement is the insurer paying its own bill. Counting its credit as
     * member activity would cancel out the very usage the report exists to show.
     */
    public function test_a_settlement_is_not_counted_as_usage(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);
        $card = $this->indebtedCard($insurer, '900.00');
        $this->ledger->settleCard($card);

        $report = $this->ledger->usage($insurer->fresh(), Carbon::now()->subDay(), Carbon::now());

        $this->assertSame('900.00', $report['spent']);
        $this->assertSame('0.00', $report['refunded'], 'the settlement was counted as money back to the member');
        $this->assertSame('900.00', $report['settlements']);
        $this->assertSame('5000.00', $report['deposits']);
    }

    public function test_the_report_only_covers_the_window_it_was_asked_for(): void
    {
        $insurer = $this->insurer();
        $card = $this->indebtedCard($insurer, '100.00');

        CardRecord::where('patient_card_id', $card->id)->update(['created_at' => Carbon::now()->subMonths(2)]);

        $report = $this->ledger->usage($insurer->fresh(), Carbon::now()->subDays(7), Carbon::now());

        $this->assertSame('0.00', $report['spent']);
        $this->assertSame([], $report['members']);
        $this->assertSame('100.00', $report['outstanding'], 'what is owed is a standing figure, not a windowed one');
    }

    public function test_the_report_never_reaches_another_insurers_cards(): void
    {
        $ours = $this->insurer();
        $theirs = $this->insurer();
        $this->indebtedCard($theirs, '400.00');

        $report = $this->ledger->usage($ours, Carbon::now()->subDay(), Carbon::now());

        $this->assertSame('0.00', $report['spent']);
        $this->assertSame([], $report['members']);
    }
}
