<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\Billing;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Billing settings — the shape of every amount in the system.
 *
 * Two things were being decided blind here. The currency code RE-LABELS what
 * is already recorded and does not convert it, and the page that changes it
 * said nothing; and the separators were each validated but never against each
 * other, so "1.234.567.89" was a saveable way to write money.
 */
class BillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Billing::class);
    }

    private function anInvoice(?Hospital $hospital = null): Invoice
    {
        $hospital ??= $this->hospital;

        $patient = Patient::factory()->create(['hospital_id' => $hospital->id]);
        $visit = Visit::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id]);

        return Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'hospital_id' => $hospital->id,
            'visit_id' => $visit->id,
            'patient_id' => $patient->id,
            'invoice_no' => 'INV-'.$visit->id,
            'currency' => 'UGX',
            'subtotal' => '30000', 'tax_total' => '0', 'discount' => '0',
            'total' => '30000', 'amount_paid' => '0', 'balance' => '30000',
            'status' => \App\Enums\InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);
    }

    // ── Two separators that are the same character ───────────────────────

    /** `number_format(1234567.89, 2, '.', '.')` is "1.234.567.89". */
    public function test_the_separators_have_to_differ(): void
    {
        $this->page()
            ->set('thousands_separator', '.')
            ->set('decimal_separator', '.')
            ->call('save')
            ->assertHasErrors('decimal_separator');
    }

    public function test_a_comma_and_a_full_stop_are_fine_either_way_round(): void
    {
        $this->page()
            ->set('thousands_separator', '.')
            ->set('decimal_separator', ',')
            ->set('decimals', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(',', $this->hospital->fresh()->settings['billing']['decimal_separator']);
    }

    public function test_no_thousands_separator_at_all_is_still_allowed(): void
    {
        $this->page()
            ->set('thousands_separator', '')
            ->set('decimal_separator', '.')
            ->call('save')
            ->assertHasNoErrors();
    }

    // ── Re-labelling money that already exists ───────────────────────────

    /**
     * Four hundred invoices in shillings become four hundred invoices in
     * dollars, at the same numbers. The page has to say so before it happens.
     */
    public function test_changing_the_currency_is_flagged_once_money_exists(): void
    {
        $this->anInvoice();

        $page = $this->page()->set('currency_code', 'USD');

        $this->assertTrue($page->instance()->currencyIsChanging());
        $page->assertSee('are already recorded in')->assertSee('nothing is converted');
    }

    public function test_nothing_is_flagged_on_a_hospital_with_no_money_yet(): void
    {
        $page = $this->page()->set('currency_code', 'USD');

        $this->assertFalse($page->instance()->currencyIsChanging());
        $page->assertDontSee('nothing is converted');
    }

    public function test_nothing_is_flagged_when_the_currency_is_not_changing(): void
    {
        $this->anInvoice();

        $this->assertFalse($this->page()->set('currency_code', 'UGX')->instance()->currencyIsChanging());
    }

    /** Case is not a change: "ugx" is the currency it is already in. */
    public function test_the_same_code_in_another_case_is_not_a_change(): void
    {
        $this->anInvoice();

        $this->assertFalse($this->page()->set('currency_code', 'ugx')->instance()->currencyIsChanging());
    }

    public function test_the_count_is_what_is_actually_on_the_books(): void
    {
        $this->anInvoice();
        $this->anInvoice();

        $recorded = $this->page()->instance()->recorded();

        $this->assertSame(2, $recorded['invoices']);
        $this->assertSame('UGX', $recorded['currency']);
    }

    /** …and it is this hospital's books. */
    public function test_another_hospitals_invoices_are_not_counted(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $this->anInvoice($other);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertSame(0, $this->page()->instance()->recorded()['invoices']);
    }

    // ── A currency taken whole ───────────────────────────────────────────

    /** A shilling has no cents; a dollar has two. */
    public function test_choosing_a_currency_brings_its_symbol_and_its_decimals(): void
    {
        $this->page()->call('useCurrency', 'USD')
            ->assertSet('currency_code', 'USD')
            ->assertSet('currency_symbol', '$')
            ->assertSet('decimals', 2);

        $this->page()->call('useCurrency', 'UGX')
            ->assertSet('currency_symbol', 'USh')
            ->assertSet('decimals', 0);
    }

    public function test_a_currency_nobody_has_heard_of_changes_nothing(): void
    {
        $this->page()
            ->set('currency_code', 'UGX')
            ->call('useCurrency', 'XYZ')
            ->assertSet('currency_code', 'UGX');
    }

    // ── The preview is the whole invoice, not just an amount ─────────────

    public function test_the_amount_preview_follows_the_format(): void
    {
        $page = $this->page()->call('useCurrency', 'UGX')->set('thousands_separator', ',');

        $this->assertSame('USh1,234,568', $page->instance()->preview());

        $page->call('useCurrency', 'USD');
        $this->assertSame('$1,234,567.89', $page->instance()->preview());

        $page->set('currency_position', 'after');
        $this->assertSame('1,234,567.89 $', $page->instance()->preview());
    }

    public function test_the_prefix_is_shown_as_the_number_it_produces(): void
    {
        $page = $this->page()->set('invoice_prefix', 'BILL');

        $this->assertSame('BILL-'.now()->format('Y').'-00001', $page->instance()->invoicePreview());
    }

    /** A rate is not an amount, so the page works one out. */
    public function test_the_tax_preview_is_a_worked_example(): void
    {
        $page = $this->page()
            ->call('useCurrency', 'UGX')
            ->set('tax_enabled', true)
            ->set('tax_rate', '18');

        $tax = $page->instance()->taxPreview();

        $this->assertSame('USh100,000', $tax['net']);
        $this->assertSame('USh18,000', $tax['tax']);
        $this->assertSame('USh118,000', $tax['gross']);
    }

    public function test_with_tax_off_the_patient_pays_the_bill(): void
    {
        $page = $this->page()->call('useCurrency', 'UGX')->set('tax_enabled', false)->set('tax_rate', '18');

        $tax = $page->instance()->taxPreview();

        $this->assertSame($tax['net'], $tax['gross'], 'a rate with tax switched off charges nothing');
    }

    /** The tax fields are meaningless with tax off, so they are not asked for. */
    public function test_the_tax_fields_appear_only_when_tax_is_on(): void
    {
        $this->page()->set('tax_enabled', false)->assertDontSee('Rate (%)');
        $this->page()->set('tax_enabled', true)->assertSee('Rate (%)');
    }

    // ── Saving keeps what it did not ask for ─────────────────────────────

    /** The letterhead page writes the footer into this same key. */
    public function test_saving_does_not_drop_what_another_page_stored_here(): void
    {
        $this->hospital->settings = ['billing' => ['invoice_footer' => 'Set from the letterhead.', 'something_else' => 'kept']];
        $this->hospital->save();

        $this->page()->call('save')->assertHasNoErrors();

        $this->assertSame('kept', $this->hospital->fresh()->settings['billing']['something_else']);
    }

    public function test_what_is_saved_is_what_the_rest_of_the_system_formats_with(): void
    {
        $this->page()->call('useCurrency', 'USD')->set('thousands_separator', ',')->call('save')->assertHasNoErrors();

        app()->forgetInstance(HospitalSettings::class);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertSame('$1,500.00', HospitalSettings::money('1500'));
    }

    public function test_only_somebody_who_may_manage_settings_can_open_it(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        $this->actingAs($nurse)->get(route('admin.settings.billing'))->assertForbidden();
    }
}
