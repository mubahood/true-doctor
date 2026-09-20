<?php

namespace Tests\Feature\Livewire;

use App\Enums\CardHolderRelationship;
use App\Enums\ClaimStatus;
use App\Livewire\Cards\Index as CardsIndex;
use App\Livewire\FinancialYears\Index as YearsIndex;
use App\Livewire\InsuranceClaims\Index as ClaimsIndex;
use App\Livewire\InsuranceProviders\Index as ProvidersIndex;
use App\Models\CardHolder;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\FinancialYearService;
use App\Services\InsuranceLedgerService;
use App\Services\InsuranceService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reading a record without leaving the list it is in.
 *
 * The house rule (docs/design-system.md) is that a RECORD opens in a dialog
 * over the list while a WORKSPACE — a visit, an invoice, a patient — gets a
 * page of its own. These four lists were the last that still navigated away to
 * answer a question the reader was going to ask of the next row too.
 *
 * What these tests hold each dialog to is not that it opens, but that it
 * carries the things the row could not: the figures somebody was subtracting
 * in their head, and the rows behind the figure.
 */
class QuickViewTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = $this->userWith('hospital_admin');
        $this->actingAs($this->admin);
    }

    private function userWith(string $role): User
    {
        $user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function patient(array $attrs = []): Patient
    {
        return Patient::factory()->create($attrs + ['hospital_id' => $this->hospital->id]);
    }

    // ── Cards ────────────────────────────────────────────────────────────

    public function test_a_card_opens_over_the_list_with_its_money_and_its_people(): void
    {
        $cards = app(CardService::class);
        $holderPatient = $this->patient(['first_name' => 'Amina', 'last_name' => 'Nakato']);
        $owner = $this->patient(['first_name' => 'Joseph', 'last_name' => 'Okello']);

        $card = $cards->issue($owner, ['accepts_credit' => true, 'max_credit' => '50000.00']);
        $cards->credit($card, '20000.00', 'Deposit at the desk', $this->admin->id);

        CardHolder::create([
            'hospital_id' => $this->hospital->id,
            'patient_card_id' => $card->id,
            'patient_id' => $holderPatient->id,
            'relationship' => CardHolderRelationship::Spouse,
            'added_by' => $this->admin->id,
        ]);

        Livewire::test(CardsIndex::class)
            ->call('peek', $card->id)
            ->assertSet('showPeek', true)
            // The figure the row showed…
            ->assertSee('Joseph Okello')
            // …the one it made the reader work out…
            ->assertSee('Still spendable')
            // …who else may spend it…
            ->assertSee('Amina Nakato')
            ->assertSee('Spouse')
            // …and what it was last spent on.
            ->assertSee('Deposit at the desk');
    }

    /** The whole point: the balance plus the credit that is still unused. */
    public function test_the_card_dialog_says_what_is_still_spendable_not_just_the_balance(): void
    {
        $cards = app(CardService::class);
        $card = $cards->issue($this->patient(), ['accepts_credit' => true, 'max_credit' => '30000.00']);
        $cards->debit($card, '10000.00', null);

        $component = Livewire::test(CardsIndex::class)->call('peek', $card->id);
        $peeked = $component->instance()->peeked();

        $this->assertNotNull($peeked);
        $this->assertSame('-10000.00', (string) $peeked->balance);
        $this->assertSame('10000.00', $peeked->debt());
        $this->assertSame('20000.00', $peeked->spendable());
    }

    public function test_the_card_dialog_shows_the_five_most_recent_entries_only(): void
    {
        $cards = app(CardService::class);
        $card = $cards->issue($this->patient(), []);

        for ($i = 1; $i <= 7; $i++) {
            $cards->credit($card, '100.00', "Top-up {$i}", $this->admin->id);
        }

        $component = Livewire::test(CardsIndex::class)->call('peek', $card->id);

        $this->assertCount(5, $component->instance()->peekedRecords());
        $component->assertSee('Top-up 7')->assertDontSee('Top-up 1');
    }

    public function test_closing_the_card_dialog_forgets_which_card_it_was(): void
    {
        $card = app(CardService::class)->issue($this->patient(), []);

        Livewire::test(CardsIndex::class)
            ->call('peek', $card->id)
            ->call('closePeek')
            ->assertSet('showPeek', false)
            ->assertSet('peekId', null);
    }

    public function test_another_hospitals_card_cannot_be_opened_from_the_dialog(): void
    {
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);
        $theirCard = app(CardService::class)->issue(
            Patient::factory()->create(['hospital_id' => $theirs->id]), []
        );
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(CardsIndex::class)->call('peek', $theirCard->id);
    }

    /** A balance is not something to show a role that may not read cards. */
    public function test_a_role_without_card_permission_cannot_open_the_card_dialog(): void
    {
        $card = app(CardService::class)->issue($this->patient(), []);
        $this->actingAs($this->userWith('doctor'));

        Livewire::test(CardsIndex::class)->assertForbidden();
    }

    // ── Insurance claims ─────────────────────────────────────────────────

    private function claim(string $price = '100000.00', ?string $amount = null): InsuranceClaim
    {
        $patient = $this->patient();
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $price]);
        app(BillingService::class)->orderService($visit->fresh(), $service->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $this->hospital->id]);

        return app(InsuranceService::class)->createClaim([
            'patient_id' => $patient->id,
            'insurance_provider_id' => $provider->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount ?? $price,
        ], $this->admin->id);
    }

    public function test_a_claim_opens_over_the_list_with_the_invoice_behind_it(): void
    {
        $claim = $this->claim('100000.00', '60000.00');

        Livewire::test(ClaimsIndex::class)
            ->call('peek', $claim->id)
            ->assertSet('showPeek', true)
            ->assertSee($claim->claim_no)
            ->assertSee($claim->invoice->invoice_no)
            // The comparison the row left the reader to make.
            ->assertSee('covers part of it')
            ->assertSee('Invoice total');
    }

    /** Opening a claim to find there is nothing left to do is a wasted trip. */
    public function test_the_claim_dialog_says_where_the_claim_can_go_next(): void
    {
        $claim = $this->claim();

        Livewire::test(ClaimsIndex::class)
            ->call('peek', $claim->id)
            ->assertSee('Submitted or Cancelled');

        app(InsuranceService::class)->transition($claim, ClaimStatus::Cancelled, $this->admin->id, 'Raised twice');

        Livewire::test(ClaimsIndex::class)
            ->call('peek', $claim->id)
            ->assertSee('This claim is closed.');
    }

    public function test_another_hospitals_claim_cannot_be_opened_from_the_dialog(): void
    {
        $claim = $this->claim();
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(ClaimsIndex::class)->call('peek', $claim->id);
    }

    // ── Insurance providers ──────────────────────────────────────────────

    public function test_an_insurer_opens_over_the_directory_with_the_figure_nobody_could_see(): void
    {
        $provider = InsuranceProvider::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Jubilee Health',
        ]);
        app(InsuranceLedgerService::class)->deposit($provider, '100000.00', null, 'Q1 float', $this->admin->id);

        $cards = app(CardService::class);
        $inDebt = $cards->issue($this->patient(), [
            'insurance_provider_id' => $provider->id,
            'accepts_credit' => true,
            'max_credit' => '500000.00',
        ]);
        $cards->debit($inDebt, '40000.00', null);
        $cards->issue($this->patient(), ['insurance_provider_id' => $provider->id]);

        $component = Livewire::test(ProvidersIndex::class)->call('peek', $provider->id);
        $figures = $component->instance()->peekedFigures();

        $this->assertSame(2, $figures['cards']);
        $this->assertSame(1, $figures['inDebt']);
        $this->assertSame('40000.00', $figures['owed']);
        // The float covers the debt with 60,000 to spare — the subtraction the
        // directory made the reader do in their head.
        $this->assertSame('60000.00', $figures['shortfall']);

        $component->assertSee('Left after clearing them')->assertSee('Q1 float');
    }

    public function test_the_insurer_dialog_names_a_float_that_cannot_clear_its_members(): void
    {
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $this->hospital->id]);
        app(InsuranceLedgerService::class)->deposit($provider, '10000.00', null, null, $this->admin->id);

        $card = app(CardService::class)->issue($this->patient(), [
            'insurance_provider_id' => $provider->id,
            'accepts_credit' => true,
            'max_credit' => '500000.00',
        ]);
        app(CardService::class)->debit($card, '25000.00', null);

        $component = Livewire::test(ProvidersIndex::class)->call('peek', $provider->id);

        $this->assertSame('-15000.00', $component->instance()->peekedFigures()['shortfall']);
        $component->assertSee('Short of clearing them');
    }

    /** Two dialogs stacked is how somebody edits one record while reading another. */
    public function test_editing_from_the_insurer_dialog_closes_it_first(): void
    {
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $this->hospital->id]);

        Livewire::test(ProvidersIndex::class)
            ->call('peek', $provider->id)
            ->call('editPeeked')
            ->assertSet('showPeek', false)
            ->assertSet('showForm', true)
            ->assertSet('editingId', $provider->id);
    }

    public function test_a_role_that_may_not_read_insurance_cannot_open_the_directory(): void
    {
        $this->actingAs($this->userWith('doctor'));

        Livewire::test(ProvidersIndex::class)->assertForbidden();
    }

    // ── Financial years ──────────────────────────────────────────────────

    private function year(string $name = 'FY 2026'): FinancialYear
    {
        return app(FinancialYearService::class)->create([
            'name' => $name,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ]);
    }

    public function test_a_period_opens_over_the_list_with_the_roll_up_the_page_would_have_shown(): void
    {
        $year = $this->year();

        $component = Livewire::test(YearsIndex::class)->call('peek', $year->id);
        $report = $component->instance()->peekedReport();

        $this->assertNotNull($report);
        $this->assertSame(app(FinancialYearService::class)->report($year), $report);

        $component
            ->assertSee('Invoiced')
            ->assertSee('Collected')
            ->assertSee('Still outstanding')
            ->assertSee('01 Jan 2026');
    }

    public function test_the_period_dialog_says_whether_anything_may_still_be_posted_into_it(): void
    {
        $year = $this->year();

        Livewire::test(YearsIndex::class)
            ->call('peek', $year->id)
            ->assertSee('invoices and payments dated inside it are allowed');

        app(FinancialYearService::class)->close($year);

        Livewire::test(YearsIndex::class)
            ->call('peek', $year->id)
            ->assertSee('nothing new can be dated inside it');
    }

    /** Closing from inside the dialog must change what the dialog is showing. */
    public function test_closing_a_period_from_the_dialog_re_reads_it(): void
    {
        $year = $this->year();

        $component = Livewire::test(YearsIndex::class)
            ->call('peek', $year->id)
            ->call('close', $year->id);

        $this->assertFalse($component->instance()->peeked()->isOpen());
        $component->assertSee('nothing new can be dated inside it');
    }

    public function test_another_hospitals_period_cannot_be_opened_from_the_dialog(): void
    {
        $year = $this->year();
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(YearsIndex::class)->call('peek', $year->id);
    }

    // ── The rule itself ──────────────────────────────────────────────────

    /**
     * No record list may make its name the only way in.
     *
     * Every one of these four used to put a wire:navigate on the row's own
     * name, which is the click somebody makes by reflex — and it took them off
     * the list. The dialog is what that click has to do now.
     */
    public function test_no_record_list_hangs_its_only_way_in_on_a_link_away(): void
    {
        $lists = [
            'cards' => 'card-peek',
            'insurance-claims' => 'claim-peek',
            'insurance-providers' => 'provider-peek',
            'financial-years' => 'financial-year-peek',
            'admissions' => 'admission-peek',
            'invoices' => 'invoice-peek',
            'patients' => 'patient-peek',
            'card-records' => 'card-record-peek',
            'beds' => 'bed-record-peek',
            'wards' => 'ward-peek',
            'rooms' => 'room-peek',
            'departments' => 'department-peek',
            'services' => 'service-peek',
            'lab-tests' => 'lab-test-peek',
            'radiology-studies' => 'radiology-study-peek',
            'staff' => 'staff-peek',
            'users' => 'user-peek',
            'stock-categories' => 'stock-category-peek',
        ];

        foreach ($lists as $page => $partial) {
            $blade = file_get_contents(resource_path("views/livewire/{$page}/index.blade.php"));

            $this->assertStringContainsString(
                "@include('livewire.partials.{$partial}')",
                (string) $blade,
                "The {$page} list does not include its quick view."
            );
            $this->assertStringContainsString(
                'wire:click="peek(',
                (string) $blade,
                "The {$page} list has no control that opens a record over it."
            );
            $this->assertFileExists(resource_path("views/livewire/partials/{$partial}.blade.php"));
        }
    }
}
