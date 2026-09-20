<?php

namespace Tests\Feature\Livewire;

use App\Enums\CardHolderRelationship;
use App\Enums\CardStatus;
use App\Livewire\CardRecords\Index as CardRecords;
use App\Livewire\Cards\Index as CardsIndex;
use App\Livewire\Cards\Show as CardShow;
use App\Livewire\InsuranceProviders\Show as ProviderShow;
use App\Models\CardHolder;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\InsuranceTransaction;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\User;
use App\Services\CardService;
use App\Services\InsuranceLedgerService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The cards section: the list, one card, the ledger, and the insurer behind it
 * (docs/cards.md).
 *
 * The rule these tests hold the section to is that it does not CONTRADICT the
 * panel on the patient page. Same rows, same figures, same service — the panel
 * simply filters to one person, and every control exists exactly once.
 */
class CardSectionTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    private CardService $cards;

    private InsuranceLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->cards = app(CardService::class);
        $this->ledger = app(InsuranceLedgerService::class);
        $this->admin = $this->userWith('hospital_admin');
        $this->actingAs($this->admin);
    }

    private function userWith(string $role): User
    {
        $user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function insurer(string $name = 'Jubilee'): InsuranceProvider
    {
        return InsuranceProvider::create(['name' => $name, 'is_active' => true]);
    }

    // ── The list ─────────────────────────────────────────────────────────

    public function test_the_list_shows_every_card_and_what_it_adds_up_to(): void
    {
        $inFunds = $this->cards->issue($this->patient(), []);
        $this->cards->credit($inFunds, '500.00', null);

        $inDebt = $this->cards->issue($this->patient(), ['accepts_credit' => true, 'max_credit' => '1000.00']);
        $this->cards->debit($inDebt, '200.00', null);

        $component = Livewire::test(CardsIndex::class)
            ->assertSee($inFunds->masked())
            ->assertSee($inDebt->masked());

        $totals = $component->instance()->totals();

        $this->assertSame(2, $totals['cards']);
        $this->assertSame('500.00', $totals['held']);
        $this->assertSame('200.00', $totals['owed']);
    }

    /** The list a settlement run is made from. */
    public function test_the_list_narrows_to_the_cards_in_debt(): void
    {
        $square = $this->cards->issue($this->patient(), []);
        $owing = $this->cards->issue($this->patient(), ['accepts_credit' => true, 'max_credit' => '100.00']);
        $this->cards->debit($owing, '40.00', null);

        Livewire::test(CardsIndex::class)
            ->set('owing', true)
            ->assertSee($owing->masked())
            ->assertDontSee($square->masked());
    }

    public function test_the_list_narrows_to_one_insurer(): void
    {
        $insurer = $this->insurer();
        $theirs = $this->cards->issue($this->patient(), ['insurance_provider_id' => $insurer->id]);
        $own = $this->cards->issue($this->patient(), []);

        Livewire::test(CardsIndex::class)
            ->set('insurer', (string) $insurer->id)
            ->assertSee($theirs->masked())
            ->assertDontSee($own->masked())
            ->set('insurer', 'own')
            ->assertSee($own->masked())
            ->assertDontSee($theirs->masked());
    }

    public function test_a_card_is_found_by_the_patient_it_belongs_to(): void
    {
        $patient = $this->patient();
        $card = $this->cards->issue($patient, []);
        $other = $this->cards->issue($this->patient(), []);

        Livewire::test(CardsIndex::class)
            ->set('search', $patient->patient_no)
            ->assertSee($card->masked())
            ->assertDontSee($other->masked());
    }

    public function test_another_hospitals_cards_are_never_listed(): void
    {
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);
        $theirCard = $this->cards->issue(Patient::factory()->create(['hospital_id' => $theirs->id]), []);
        app(CurrentHospital::class)->set($this->hospital->id);

        Livewire::test(CardsIndex::class)->assertDontSee($theirCard->masked());
    }

    /** A balance is not something to show a role that may not act on it. */
    public function test_a_role_without_card_permission_cannot_open_the_list(): void
    {
        $this->actingAs($this->userWith('doctor'));

        Livewire::test(CardsIndex::class)->assertForbidden();
    }

    // ── One card ─────────────────────────────────────────────────────────

    public function test_the_card_page_shows_its_terms_its_people_and_its_ledger(): void
    {
        $owner = $this->patient();
        $child = $this->patient();
        $card = $this->cards->issue($owner, ['accepts_credit' => true, 'max_credit' => '300.00']);
        $this->cards->addHolder($card, $child, CardHolderRelationship::Child, $this->admin->id);
        $this->cards->credit($card, '100.00', 'Opening top-up');

        Livewire::test(CardShow::class, ['uuid' => $card->uuid])
            ->assertSee($card->masked())
            ->assertSee($owner->fullName())
            ->assertSee($child->fullName())
            ->assertSee('Child')
            ->assertSee('Opening top-up');
    }

    public function test_the_card_page_tops_up_and_charges(): void
    {
        $card = $this->cards->issue($this->patient(), []);

        Livewire::test(CardShow::class, ['uuid' => $card->uuid])
            ->call('openTxn', 'credit')
            ->set('amount', '250.00')
            ->set('description', 'Cash at the counter')
            ->call('postTxn')
            ->assertHasNoErrors()
            ->assertSet('showTxn', false);

        $this->assertSame('250.00', (string) $card->fresh()->balance);

        Livewire::test(CardShow::class, ['uuid' => $card->uuid])
            ->call('openTxn', 'debit')
            ->set('amount', '50.00')
            ->call('postTxn')
            ->assertHasNoErrors();

        $this->assertSame('200.00', (string) $card->fresh()->balance);
    }

    /** A card with nothing on it says so on the field, not on a new page. */
    public function test_spending_what_is_not_there_is_refused_inline(): void
    {
        $card = $this->cards->issue($this->patient(), []);

        Livewire::test(CardShow::class, ['uuid' => $card->uuid])
            ->call('openTxn', 'debit')
            ->set('amount', '10.00')
            ->call('postTxn')
            ->assertHasErrors('amount')
            ->assertSet('showTxn', true);

        $this->assertSame('0.00', (string) $card->fresh()->balance);
    }

    public function test_terms_can_be_changed_after_the_card_was_issued(): void
    {
        $card = $this->cards->issue($this->patient(), []);

        Livewire::test(CardShow::class, ['uuid' => $card->uuid])
            ->call('openTerms')
            ->set('status', CardStatus::Suspended->value)
            ->set('accepts_credit', true)
            ->set('max_credit', '400.00')
            ->call('saveTerms')
            ->assertHasNoErrors()
            ->assertSet('showTerms', false);

        $fresh = $card->fresh();
        $this->assertSame(CardStatus::Suspended, $fresh->status);
        $this->assertTrue($fresh->accepts_credit);
        $this->assertSame('400.00', (string) $fresh->max_credit);
    }

    /** A limit cannot be pulled below a debt already taken against it. */
    public function test_lowering_the_limit_under_an_existing_debt_is_refused_inline(): void
    {
        $card = $this->cards->issue($this->patient(), ['accepts_credit' => true, 'max_credit' => '500.00']);
        $this->cards->debit($card, '300.00', null);

        Livewire::test(CardShow::class, ['uuid' => $card->uuid])
            ->call('openTerms')
            ->set('max_credit', '100.00')
            ->call('saveTerms')
            ->assertHasErrors('max_credit')
            ->assertSet('showTerms', true);

        $this->assertSame('500.00', (string) $card->fresh()->max_credit);
    }

    public function test_somebody_is_added_to_the_card_and_taken_off_it_again(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $spouse = $this->patient();

        $component = Livewire::test(CardShow::class, ['uuid' => $card->uuid])
            ->call('openHolder')
            ->call('picked', 'holder_patient_id', $spouse->id)
            ->set('relationship', 'spouse')
            ->call('addHolder')
            ->assertHasNoErrors()
            ->assertSet('showHolder', false);

        $this->assertTrue($card->fresh()->covers($spouse));

        $holder = CardHolder::where('patient_card_id', $card->id)->firstOrFail();
        $component->call('revokeHolder', $holder->id);

        $this->assertFalse($card->fresh()->covers($spouse));
    }

    public function test_another_hospitals_card_cannot_be_opened(): void
    {
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);
        $theirCard = $this->cards->issue(Patient::factory()->create(['hospital_id' => $theirs->id]), []);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(CardShow::class, ['uuid' => $theirCard->uuid]);
    }

    public function test_a_holder_of_another_card_cannot_be_revoked_through_this_one(): void
    {
        $mine = $this->cards->issue($this->patient(), []);
        $other = $this->cards->issue($this->patient(), []);
        $holder = $this->cards->addHolder($other, $this->patient(), CardHolderRelationship::Other);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(CardShow::class, ['uuid' => $mine->uuid])->call('revokeHolder', $holder->id);
    }

    // ── The hospital's whole ledger ──────────────────────────────────────

    public function test_the_records_page_lists_every_movement_with_its_totals(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $this->cards->credit($card, '300.00', 'Top-up');
        $this->cards->debit($card, '120.00', 'Consultation');

        $component = Livewire::test(CardRecords::class)
            ->assertSee('Top-up')
            ->assertSee('Consultation');

        $totals = $component->instance()->totals();

        $this->assertSame('300.00', $totals['in']);
        $this->assertSame('120.00', $totals['out']);
        $this->assertSame('180.00', $totals['net']);
    }

    public function test_the_records_page_narrows_by_direction_and_insurer(): void
    {
        $insurer = $this->insurer();
        $insured = $this->cards->issue($this->patient(), ['insurance_provider_id' => $insurer->id, 'accepts_credit' => true, 'max_credit' => '500.00']);
        $own = $this->cards->issue($this->patient(), []);

        $this->cards->debit($insured, '90.00', 'Insured treatment');
        $this->cards->credit($own, '70.00', 'Own top-up');

        Livewire::test(CardRecords::class)
            ->set('type', 'debit')
            ->assertSee('Insured treatment')
            ->assertDontSee('Own top-up')
            ->set('type', '')
            ->set('insurer', (string) $insurer->id)
            ->assertSee('Insured treatment')
            ->assertDontSee('Own top-up');
    }

    public function test_a_ledger_row_can_be_neither_edited_nor_deleted_from_this_page(): void
    {
        $this->assertFalse(method_exists(CardRecords::class, 'delete'), 'the ledger grew a delete');
        $this->assertFalse(method_exists(CardRecords::class, 'edit'), 'the ledger grew an edit');
    }

    // ── The insurer ──────────────────────────────────────────────────────

    public function test_the_insurer_page_takes_a_deposit_and_clears_its_members(): void
    {
        $insurer = $this->insurer();
        $card = $this->cards->issue($this->patient(), [
            'insurance_provider_id' => $insurer->id,
            'accepts_credit' => true,
            'max_credit' => '5000.00',
        ]);
        $this->cards->debit($card, '900.00', 'Treatment');

        Livewire::test(ProviderShow::class, ['insuranceProvider' => $insurer])
            ->call('openDeposit')
            ->set('amount', '2000.00')
            ->set('reference', 'RTGS-11')
            ->call('saveDeposit')
            ->assertHasNoErrors()
            ->call('settleAll');

        $this->assertSame('0.00', (string) $card->fresh()->balance);
        $this->assertSame('1100.00', (string) $insurer->fresh()->float_balance);
    }

    public function test_the_insurer_page_clears_one_card_on_its_own(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '5000.00', null, null);

        $card = $this->cards->issue($this->patient(), [
            'insurance_provider_id' => $insurer->id, 'accepts_credit' => true, 'max_credit' => '5000.00',
        ]);
        $this->cards->debit($card, '250.00', 'Treatment');

        Livewire::test(ProviderShow::class, ['insuranceProvider' => $insurer->fresh()])
            ->call('settleOne', $card->id);

        $this->assertSame('0.00', (string) $card->fresh()->balance);
    }

    public function test_a_card_of_another_insurer_cannot_be_cleared_from_this_page(): void
    {
        $ours = $this->insurer('Ours');
        $theirs = $this->insurer('Theirs');
        $this->ledger->deposit($ours, '5000.00', null, null);

        $theirCard = $this->cards->issue($this->patient(), [
            'insurance_provider_id' => $theirs->id, 'accepts_credit' => true, 'max_credit' => '5000.00',
        ]);
        $this->cards->debit($theirCard, '100.00', null);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ProviderShow::class, ['insuranceProvider' => $ours->fresh()])->call('settleOne', $theirCard->id);
    }

    public function test_the_float_is_corrected_by_another_line_that_says_why(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '1000.00', null, null);

        Livewire::test(ProviderShow::class, ['insuranceProvider' => $insurer->fresh()])
            ->call('openAdjust')
            ->set('direction', 'remove')
            ->set('adjustAmount', '150.00')
            ->set('reason', 'Deposit was entered twice')
            ->call('saveAdjust')
            ->assertHasNoErrors();

        $this->assertSame('850.00', (string) $insurer->fresh()->float_balance);
        $this->assertSame(
            2,
            InsuranceTransaction::where('insurance_provider_id', $insurer->id)->count(),
            'the correction overwrote the deposit instead of standing beside it',
        );
    }

    public function test_a_correction_must_say_why(): void
    {
        $insurer = $this->insurer();
        $this->ledger->deposit($insurer, '1000.00', null, null);

        Livewire::test(ProviderShow::class, ['insuranceProvider' => $insurer->fresh()])
            ->call('openAdjust')
            ->set('adjustAmount', '10.00')
            ->set('reason', '')
            ->call('saveAdjust')
            ->assertHasErrors('reason');

        $this->assertSame('1000.00', (string) $insurer->fresh()->float_balance);
    }

    /** Reading an insurer's float is not the same right as spending it. */
    public function test_a_role_that_may_only_read_insurance_cannot_move_the_float(): void
    {
        $this->actingAs($this->userWith('receptionist'));
        $insurer = $this->insurer();

        Livewire::test(ProviderShow::class, ['insuranceProvider' => $insurer])
            ->assertSet('mayManage', false)
            ->call('openDeposit')
            ->assertForbidden();
    }

    public function test_the_usage_window_follows_the_dates_on_the_page(): void
    {
        $insurer = $this->insurer();
        $card = $this->cards->issue($this->patient(), [
            'insurance_provider_id' => $insurer->id, 'accepts_credit' => true, 'max_credit' => '5000.00',
        ]);
        $this->cards->debit($card, '400.00', 'Treatment');

        $component = Livewire::test(ProviderShow::class, ['insuranceProvider' => $insurer]);
        $this->assertSame('400.00', $component->instance()->usage()['spent']);

        $component->set('from', now()->subYear()->toDateString())
            ->set('to', now()->subMonths(6)->toDateString());

        $this->assertSame('0.00', $component->instance()->usage()['spent']);
    }

    public function test_the_usage_statement_downloads_as_a_pdf(): void
    {
        $insurer = $this->insurer();
        $card = $this->cards->issue($this->patient(), [
            'insurance_provider_id' => $insurer->id, 'accepts_credit' => true, 'max_credit' => '5000.00',
        ]);
        $this->cards->debit($card, '400.00', 'Treatment');

        $response = $this->get(route('admin.insurance-providers.usage', [
            $insurer, 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(),
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    // ── The panel and the section do not contradict ──────────────────────

    /**
     * Terms, holders and the insurer are edited on the card's own page and
     * nowhere else. Two copies of a control over a credit limit is how the two
     * copies come to disagree.
     */
    public function test_the_patient_panel_does_not_grow_a_second_copy_of_the_card_controls(): void
    {
        $panel = \App\Livewire\Patients\Panels\Cards::class;

        foreach (['saveTerms', 'addHolder', 'revokeHolder', 'settle'] as $control) {
            $this->assertFalse(
                method_exists($panel, $control),
                "the patient panel has its own {$control} — it belongs to the card page alone",
            );
        }
    }

    /** …and conversely, the panel is still where a card is issued to a patient. */
    public function test_the_patient_panel_issues_a_card_and_the_section_reads_it(): void
    {
        $patient = $this->patient();
        $insurer = $this->insurer();

        Livewire::withoutLazyLoading();
        Livewire::test(\App\Livewire\Patients\Panels\Cards::class, ['patientId' => $patient->id])
            ->call('openIssue')
            ->set('insurance_provider_id', $insurer->id)
            ->set('member_no', 'M-900')
            ->call('issue')
            ->assertHasNoErrors();

        $card = PatientCard::where('patient_id', $patient->id)->firstOrFail();

        $this->assertSame($insurer->id, $card->insurance_provider_id);
        $this->assertSame('M-900', $card->member_no);

        Livewire::test(CardsIndex::class)->assertSee($card->masked())->assertSee('Jubilee');
    }
}
