<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Onboarding\Index as Wizard;
use App\Livewire\Onboarding\Steps\Billing;
use App\Livewire\Onboarding\Steps\Departments;
use App\Livewire\Onboarding\Steps\Profile;
use App\Livewire\Onboarding\Steps\Services;
use App\Livewire\Onboarding\Steps\Staff;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Support\OnboardingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The setup wizard must be completable entirely in place — every required step
 * has a form that writes through the same services and rules the full modules
 * use, and finishing a step immediately changes what the gate allows.
 */
class OnboardingWizardTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        config(['onboarding.gate_enabled' => true]);
        $this->hospital = $this->hospital();
        $this->actingAsRole('hospital_admin', $this->hospital);
    }

    private function checklist(): OnboardingStatus
    {
        return app(OnboardingStatus::class);
    }

    // ── The wizard shell ──────────────────────────────────────────────

    public function test_it_opens_on_the_first_outstanding_step_and_counts_only_required_ones(): void
    {
        $component = Livewire::test(Wizard::class)->assertOk();

        $component->assertSet('open', 'profile');
        $this->assertSame(['done' => 0, 'total' => 5, 'percent' => 0], $component->instance()->progress());
    }

    public function test_it_lists_what_is_still_blocking_access(): void
    {
        Livewire::test(Wizard::class)
            ->assertSee('Still needed')
            ->assertSee('Hospital details')
            ->assertSee('Price list')
            ->assertSee('Your team');
    }

    /**
     * The "still needed" list is a row of clickable chips, each jumping to its
     * step — a real interactive control, not the passive .badge-tb <span> used
     * for read-only status pills elsewhere. Regression coverage for both halves:
     * the right class is on the markup, and clicking one actually opens the step.
     */
    public function test_the_still_needed_chips_are_real_buttons_that_open_their_step(): void
    {
        $html = Livewire::test(Wizard::class)->set('open', '')->html();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="wz-chip"[^>]*>\s*Price list\s*<\/button>/',
            $html,
            'the "still needed" chip must be a real button styled by .wz-chip, not the passive .badge-tb span'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button\b[^>]*class="[^"]*\bbadge-tb\b/',
            $html,
            'the badge vocabulary has no button reset behind it — it must never sit on a <button>'
        );

        Livewire::test(Wizard::class)
            ->set('open', '')
            ->call('toggle', 'services')
            ->assertSet('open', 'services');
    }

    public function test_a_step_can_be_opened_and_closed(): void
    {
        Livewire::test(Wizard::class)
            ->call('toggle', 'services')->assertSet('open', 'services')
            ->call('toggle', 'services')->assertSet('open', '');
    }

    /** The "Next" button only appears once the open step is actually done. */
    public function test_the_next_button_is_hidden_until_the_open_step_is_done(): void
    {
        Livewire::test(Wizard::class)
            ->set('open', 'profile')
            ->assertDontSee('wire:click="next"', false);

        $this->completeProfile();

        Livewire::test(Wizard::class)
            ->set('open', 'profile')
            ->assertSee('wire:click="next"', false);
    }

    /**
     * Completing a step must NOT jump the admin to the next one automatically —
     * they decide when to move on, via the "Next" button `next()` reveals once
     * the open step is done. `refresh()` (fired by every step's
     * onboarding-updated) only has to catch the checklist up.
     */
    public function test_completing_a_step_does_not_move_the_admin_on_automatically(): void
    {
        $this->completeProfile();

        Livewire::test(Wizard::class)
            ->set('open', 'profile')
            ->call('refresh')
            ->assertSet('open', 'profile')
            ->assertSee('Done');
    }

    public function test_next_moves_to_the_step_right_after_the_open_one(): void
    {
        Livewire::test(Wizard::class)
            ->set('open', 'profile')
            ->call('next')
            ->assertSet('open', 'billing');
    }

    public function test_next_does_nothing_from_the_last_step(): void
    {
        Livewire::test(Wizard::class)
            ->set('open', 'catalogues')
            ->call('next')
            ->assertSet('open', 'catalogues');
    }

    public function test_staff_without_manage_settings_see_the_checklist_but_no_forms(): void
    {
        $this->actingAsRole('doctor', $this->hospital);

        Livewire::test(Wizard::class)
            ->assertOk()
            ->assertSee('Ask your hospital administrator');
    }

    // ── Step: hospital profile ────────────────────────────────────────

    public function test_the_profile_step_saves_address_and_contact_details(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'St. Mary Clinic')
            ->set('address', 'Plot 4, Kampala Road')
            ->set('phone', '+256700111222')
            ->set('timezone', 'Africa/Kampala')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $hospital = $this->hospital->fresh();
        $this->assertSame('St. Mary Clinic', $hospital->name);
        $this->assertSame('Plot 4, Kampala Road', $hospital->address);
        $this->assertSame('+256700111222', $hospital->settings['profile']['phone']);
        $this->assertTrue($this->checklist()->profileConfigured($hospital));

        // The guarantee that matters: what setup was told is what documents
        // print. These were two different keys — setup wrote `contact`, the
        // letterhead read `profile` — so a hospital could give its phone
        // number here and every invoice it ever sent carried no way to ring
        // anybody.
        $this->assertSame(
            '+256700111222',
            app(\App\Support\DocumentBrand::class)->profileOf($hospital)['phone'] ?? null,
        );
    }

    /** Hospitals set up before the two stores were merged keep their details. */
    public function test_contact_details_saved_under_the_old_key_still_reach_documents(): void
    {
        $this->hospital->address = 'Plot 4, Kampala Road';
        $this->hospital->settings = array_merge($this->hospital->settings ?? [], [
            'contact' => ['phone' => '+256700999888', 'email' => 'old@clinic.org'],
        ]);
        $this->hospital->save();

        $brand = app(\App\Support\DocumentBrand::class)->profileOf($this->hospital->fresh());

        $this->assertSame('+256700999888', $brand['phone']);
        $this->assertTrue($this->checklist()->profileConfigured($this->hospital->fresh()));
    }

    /** …and neither writer may wipe what the other owns. */
    public function test_setup_does_not_drop_the_letterheads_own_fields(): void
    {
        $this->hospital->settings = array_merge($this->hospital->settings ?? [], [
            'profile' => ['website' => 'stmary.org', 'registration' => 'REG-99'],
        ]);
        $this->hospital->save();

        Livewire::test(Profile::class)
            ->set('name', 'St. Mary Clinic')
            ->set('address', 'Plot 4, Kampala Road')
            ->set('phone', '+256700111222')
            ->set('timezone', 'Africa/Kampala')
            ->call('save')
            ->assertHasNoErrors();

        $profile = $this->hospital->fresh()->settings['profile'];

        $this->assertSame('stmary.org', $profile['website']);
        $this->assertSame('REG-99', $profile['registration']);
        $this->assertSame('+256700111222', $profile['phone']);
    }

    public function test_the_profile_step_requires_a_way_to_be_reached(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'St. Mary Clinic')
            ->set('address', 'Plot 4')
            ->set('phone', null)
            ->set('email', null)
            ->call('save')
            ->assertHasErrors(['phone']);

        $this->assertFalse($this->checklist()->profileConfigured($this->hospital->fresh()));
    }

    // ── Step: currency and billing ────────────────────────────────────

    public function test_the_billing_step_saves_the_currency_and_previews_the_format(): void
    {
        $component = Livewire::test(Billing::class)
            ->set('currency_code', 'KES')
            ->assertSet('currency_symbol', 'KSh');   // picked up from the currency

        $this->assertSame('KSh1,234,568', $component->instance()->preview());

        $component->set('invoice_prefix', 'HSP')->call('save')->assertHasNoErrors();

        $hospital = $this->hospital->fresh();
        $this->assertSame('KES', $hospital->currency);
        $this->assertSame('HSP', $hospital->settings['billing']['invoice_prefix']);
        $this->assertTrue($this->checklist()->billingConfigured($hospital));
    }

    public function test_the_billing_step_rejects_an_invalid_invoice_prefix(): void
    {
        Livewire::test(Billing::class)
            ->set('invoice_prefix', 'not a prefix!')
            ->call('save')
            ->assertHasErrors(['invoice_prefix']);
    }

    // ── Step: departments ─────────────────────────────────────────────

    public function test_the_departments_step_adds_one_by_hand(): void
    {
        Livewire::test(Departments::class)
            ->set('name', 'Dental')
            ->set('code', 'den')
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $this->assertDatabaseHas('departments', [
            'hospital_id' => $this->hospital->id, 'name' => 'Dental', 'code' => 'DEN',
        ]);
        $this->assertTrue($this->checklist()->hasDepartments($this->hospital));
    }

    public function test_the_departments_step_adds_the_common_set_in_one_click_without_duplicating(): void
    {
        Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Outpatient', 'is_active' => true]);

        Livewire::test(Departments::class)->call('addCommon')->assertDispatched('onboarding-updated');

        $this->assertSame(1, Department::where('name', 'Outpatient')->count(), 'the existing department must not be duplicated');
        $this->assertGreaterThan(1, Department::count());
    }

    public function test_the_departments_step_rejects_a_duplicate_name(): void
    {
        Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Dental']);

        Livewire::test(Departments::class)->set('name', 'Dental')->call('add')->assertHasErrors(['name']);
    }

    /** Dismissing a suggestion drops it from the preview and the batch add — the "dynamic remove". */
    public function test_a_dismissed_department_suggestion_is_never_added_and_never_reappears(): void
    {
        $component = Livewire::test(Departments::class);
        $dismissed = $component->instance()->suggestions()[0]['name'];

        $component->call('removeSuggestion', $dismissed);

        $this->assertNotContains($dismissed, collect($component->instance()->suggestions())->pluck('name')->all());

        $component->call('addCommon');
        $this->assertDatabaseMissing('departments', ['hospital_id' => $this->hospital->id, 'name' => $dismissed]);
        // Everything else still gets added.
        $this->assertGreaterThan(0, Department::count());
    }

    /** "Add a pen to edit each, or delete" — an already-added department can be changed in a popup, or removed. */
    public function test_an_already_added_department_can_be_edited_in_the_popup(): void
    {
        $department = Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Old', 'code' => 'OLD']);

        Livewire::test(Departments::class)
            ->call('edit', $department->id)
            ->assertSet('editName', 'Old')
            ->assertSet('editCode', 'OLD')
            ->set('editName', 'Dental')
            ->set('editCode', 'den')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showEdit', false);

        $department->refresh();
        $this->assertSame('Dental', $department->name);
        $this->assertSame('DEN', $department->code);
    }

    public function test_an_already_added_department_can_be_deleted(): void
    {
        $department = Department::factory()->create(['hospital_id' => $this->hospital->id]);

        Livewire::test(Departments::class)->call('delete', $department->id)->assertDispatched('onboarding-updated');

        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    // ── Step: price list ──────────────────────────────────────────────

    public function test_the_services_step_adds_a_priced_service(): void
    {
        Livewire::test(Services::class)
            ->set('name', 'Specialist consultation')
            ->set('price', '45000')
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $this->assertDatabaseHas('services', ['hospital_id' => $this->hospital->id, 'name' => 'Specialist consultation']);
        $this->assertTrue($this->checklist()->hasServices($this->hospital));
    }

    public function test_the_services_step_imports_the_starter_list_at_the_reviewed_prices(): void
    {
        $component = Livewire::test(Services::class);
        $first = $component->instance()->suggestions()[0]['name'];

        $component->set('starterPrices.'.$first, '12345')->call('addStarter')->assertHasNoErrors();

        $this->assertSame('12345.00', Service::where('name', $first)->value('price'));
        $this->assertTrue($this->checklist()->hasServices($this->hospital));
    }

    public function test_the_services_step_refuses_a_negative_starter_price(): void
    {
        $component = Livewire::test(Services::class);
        $first = $component->instance()->suggestions()[0]['name'];

        $component->set('starterPrices.'.$first, '-5')->call('addStarter');

        $this->assertDatabaseMissing('services', ['name' => $first]);
    }

    /** Dismissing a suggestion drops it from the preview and the batch add — the "dynamic remove". */
    public function test_a_dismissed_service_suggestion_is_never_added_and_never_reappears(): void
    {
        $component = Livewire::test(Services::class);
        $dismissed = $component->instance()->suggestions()[0]['name'];

        $component->call('removeSuggestion', $dismissed);

        $this->assertNotContains($dismissed, collect($component->instance()->suggestions())->pluck('name')->all());

        $component->call('addStarter')->assertHasNoErrors();
        $this->assertDatabaseMissing('services', ['hospital_id' => $this->hospital->id, 'name' => $dismissed]);
        $this->assertGreaterThan(0, Service::count());
    }

    /** The starter list spans real hospital specialties, not just a general clinic. */
    public function test_the_starter_price_list_is_grouped_by_specialty_and_covers_more_than_general_medicine(): void
    {
        $groups = Livewire::test(Services::class)->instance()->groupedSuggestions();

        $this->assertTrue($groups->has('Dental'), 'Dental services must be suggested, e.g. root canal treatment');
        $this->assertTrue($groups->has('Maternity & gynaecology'));
        $this->assertContains('Root canal treatment', $groups['Dental']->pluck('name'));
        $this->assertContains('Pap smear', $groups['Maternity & gynaecology']->pluck('name'));
    }

    /** "Add a pen to edit each, or delete" — an already-added service can be changed in a popup, or removed. */
    public function test_an_already_added_service_can_be_edited_in_the_popup(): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Old name', 'price' => '10000']);

        Livewire::test(Services::class)
            ->call('edit', $service->id)
            ->assertSet('editName', 'Old name')
            ->assertSet('showEdit', true)
            ->set('editName', 'Specialist consultation')
            ->set('editPrice', '55000')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showEdit', false);

        $service->refresh();
        $this->assertSame('Specialist consultation', $service->name);
        $this->assertSame('55000.00', $service->price);
    }

    public function test_editing_a_service_still_enforces_its_own_uniqueness_rule(): void
    {
        Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Taken']);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Mine']);

        Livewire::test(Services::class)
            ->call('edit', $service->id)
            ->set('editName', 'Taken')
            ->call('saveEdit')
            ->assertHasErrors(['editName']);
    }

    public function test_an_already_added_service_can_be_deleted(): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id]);

        Livewire::test(Services::class)->call('delete', $service->id)->assertDispatched('onboarding-updated');

        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    // ── Step: consultation rooms (recommended) ──────────────────────────

    public function test_the_rooms_step_adds_one_by_hand(): void
    {
        Livewire::test(\App\Livewire\Onboarding\Steps\Rooms::class)
            ->set('name', 'Consultation Room 3')
            ->set('type', 'consultation')
            ->set('capacity', 2)
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $this->assertDatabaseHas('rooms', [
            'hospital_id' => $this->hospital->id, 'name' => 'Consultation Room 3', 'status' => 'available',
        ]);
    }

    public function test_the_rooms_step_adds_the_suggested_set_without_duplicating(): void
    {
        \App\Models\Room::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Consultation Room 1']);

        Livewire::test(\App\Livewire\Onboarding\Steps\Rooms::class)->call('addCommon')->assertDispatched('onboarding-updated');

        $this->assertSame(1, \App\Models\Room::where('name', 'Consultation Room 1')->count());
        $this->assertGreaterThan(1, \App\Models\Room::count());
    }

    public function test_a_dismissed_room_suggestion_is_never_added(): void
    {
        $component = Livewire::test(\App\Livewire\Onboarding\Steps\Rooms::class);
        $dismissed = $component->instance()->suggestions()[0]['name'];

        $component->call('removeSuggestion', $dismissed)->call('addCommon');

        $this->assertDatabaseMissing('rooms', ['hospital_id' => $this->hospital->id, 'name' => $dismissed]);
    }

    /** "Add a pen to edit each, or delete" — an already-added room can be changed in a popup, or removed. */
    public function test_an_already_added_room_can_be_edited_in_the_popup(): void
    {
        $room = \App\Models\Room::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Old room', 'type' => 'consultation', 'capacity' => 1]);

        Livewire::test(\App\Livewire\Onboarding\Steps\Rooms::class)
            ->call('edit', $room->id)
            ->assertSet('editName', 'Old room')
            ->set('editName', 'Theatre 1')
            ->set('editType', 'theatre')
            ->set('editCapacity', 3)
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showEdit', false);

        $room->refresh();
        $this->assertSame('Theatre 1', $room->name);
        $this->assertSame('theatre', $room->type->value);
        $this->assertSame(3, $room->capacity);
    }

    public function test_an_already_added_room_can_be_deleted(): void
    {
        $room = \App\Models\Room::factory()->create(['hospital_id' => $this->hospital->id]);

        Livewire::test(\App\Livewire\Onboarding\Steps\Rooms::class)->call('delete', $room->id)->assertDispatched('onboarding-updated');

        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
    }

    // ── Step: wards and beds (recommended) ──────────────────────────────

    public function test_the_wards_step_adds_a_ward_with_its_beds_in_one_go(): void
    {
        Livewire::test(\App\Livewire\Onboarding\Steps\Wards::class)
            ->set('name', 'General ward')
            ->set('bedsCount', 3)
            ->set('dailyCharge', '25000')
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $ward = \App\Models\Ward::where('hospital_id', $this->hospital->id)->where('name', 'General ward')->firstOrFail();
        $this->assertSame(3, $ward->beds()->count());
        $this->assertSame('25000.00', $ward->beds()->first()->daily_charge);
    }

    public function test_the_wards_step_suggested_set_creates_wards_with_beds_at_the_reviewed_rate(): void
    {
        $component = Livewire::test(\App\Livewire\Onboarding\Steps\Wards::class);
        $first = $component->instance()->suggestions()[0]['name'];

        $component->set('starterBeds.'.$first, '2')->set('starterRates.'.$first, '99000')->call('addCommon')->assertHasNoErrors();

        $ward = \App\Models\Ward::where('hospital_id', $this->hospital->id)->where('name', $first)->firstOrFail();
        $this->assertSame(2, $ward->beds()->count());
        $this->assertSame('99000.00', $ward->beds()->first()->daily_charge);
    }

    public function test_the_wards_step_never_recreates_beds_for_a_ward_that_already_exists(): void
    {
        \App\Models\Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'General ward']);

        Livewire::test(\App\Livewire\Onboarding\Steps\Wards::class)->call('addCommon');

        $ward = \App\Models\Ward::where('hospital_id', $this->hospital->id)->where('name', 'General ward')->firstOrFail();
        $this->assertSame(0, $ward->beds()->count(), 'a ward the admin already created must not gain surprise beds');
    }

    public function test_a_dismissed_ward_suggestion_is_never_added(): void
    {
        $component = Livewire::test(\App\Livewire\Onboarding\Steps\Wards::class);
        $dismissed = $component->instance()->suggestions()[0]['name'];

        $component->call('removeSuggestion', $dismissed)->call('addCommon');

        $this->assertDatabaseMissing('wards', ['hospital_id' => $this->hospital->id, 'name' => $dismissed]);
    }

    /** "Add a pen to edit each, or delete" — the popup only touches name/description; beds are the Beds page's job. */
    public function test_an_already_added_ward_can_be_edited_in_the_popup(): void
    {
        $ward = \App\Models\Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Old ward']);

        Livewire::test(\App\Livewire\Onboarding\Steps\Wards::class)
            ->call('edit', $ward->id)
            ->assertSet('editName', 'Old ward')
            ->set('editName', 'General ward')
            ->set('editDescription', 'Main adult ward')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showEdit', false);

        $ward->refresh();
        $this->assertSame('General ward', $ward->name);
        $this->assertSame('Main adult ward', $ward->description);
    }

    public function test_an_already_added_ward_can_be_deleted_only_once_its_beds_are_gone(): void
    {
        $ward = \App\Models\Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        \App\Models\Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);

        // Refused while beds remain — mirrors the full Wards page.
        Livewire::test(\App\Livewire\Onboarding\Steps\Wards::class)->call('delete', $ward->id)->assertDispatched('toast');
        $this->assertDatabaseHas('wards', ['id' => $ward->id, 'deleted_at' => null]);

        // Succeeds once they're gone.
        \App\Models\Bed::where('ward_id', $ward->id)->delete();
        Livewire::test(\App\Livewire\Onboarding\Steps\Wards::class)->call('delete', $ward->id);
        $this->assertSoftDeleted('wards', ['id' => $ward->id]);
    }

    // ── Step: lab and pharmacy catalogues (recommended) ─────────────────

    public function test_the_catalogues_step_adds_a_lab_test_by_hand(): void
    {
        Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class)
            ->set('labTestName', 'Blood grouping')
            ->set('labTestPrice', '12000')
            ->call('addLabTest')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $this->assertDatabaseHas('lab_tests', ['hospital_id' => $this->hospital->id, 'name' => 'Blood grouping']);
    }

    public function test_the_catalogues_step_adds_a_stock_category_by_hand(): void
    {
        Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class)
            ->set('categoryName', 'Vaccines')
            ->set('categoryUnit', 'vial')
            ->call('addCategory')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $this->assertDatabaseHas('stock_categories', ['hospital_id' => $this->hospital->id, 'name' => 'Vaccines', 'unit' => 'vial']);
    }

    /** Either catalogue alone satisfies the step, so both suggestion lists must work independently. */
    public function test_the_catalogues_step_imports_both_starter_lists_independently(): void
    {
        Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class)
            ->call('addStarterLabTests')
            ->call('addStarterCategories')
            ->assertHasNoErrors();

        $this->assertGreaterThan(0, \App\Models\LabTest::where('hospital_id', $this->hospital->id)->count());
        $this->assertGreaterThan(0, \App\Models\StockCategory::where('hospital_id', $this->hospital->id)->count());
        $this->assertTrue($this->checklist()->steps($this->hospital)->firstWhere('key', 'catalogues')->done);
    }

    public function test_dismissed_catalogue_suggestions_are_independent_per_list(): void
    {
        $component = Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class);
        $dismissedTest = $component->instance()->labTestSuggestions()[0]['name'];
        $dismissedCategory = $component->instance()->categorySuggestions()[0]['name'];

        $component->call('removeLabTestSuggestion', $dismissedTest)
            ->call('addStarterLabTests')
            ->call('addStarterCategories');

        $this->assertDatabaseMissing('lab_tests', ['hospital_id' => $this->hospital->id, 'name' => $dismissedTest]);
        // Dismissing a lab test must not have touched the independent category list.
        $this->assertDatabaseHas('stock_categories', ['hospital_id' => $this->hospital->id, 'name' => $dismissedCategory]);
    }

    /** "Add a pen to edit each, or delete" — each catalogue's already-added rows are independently editable. */
    public function test_an_already_added_lab_test_can_be_edited_in_the_popup(): void
    {
        $test = \App\Models\LabTest::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Old test', 'price' => '5000']);

        Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class)
            ->call('editLabTest', $test->id)
            ->assertSet('editLabTestName', 'Old test')
            ->set('editLabTestName', 'Malaria RDT')
            ->set('editLabTestPrice', '9000')
            ->call('saveLabTestEdit')
            ->assertHasNoErrors()
            ->assertSet('showEditLabTest', false);

        $test->refresh();
        $this->assertSame('Malaria RDT', $test->name);
        $this->assertSame('9000.00', $test->price);
    }

    public function test_an_already_added_lab_test_can_be_deleted(): void
    {
        $test = \App\Models\LabTest::factory()->create(['hospital_id' => $this->hospital->id]);

        Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class)->call('deleteLabTest', $test->id)->assertDispatched('onboarding-updated');

        $this->assertSoftDeleted('lab_tests', ['id' => $test->id]);
    }

    public function test_an_already_added_stock_category_can_be_edited_in_the_popup(): void
    {
        $category = \App\Models\StockCategory::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Old category', 'unit' => 'unit']);

        Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class)
            ->call('editCategory', $category->id)
            ->assertSet('editCategoryName', 'Old category')
            ->set('editCategoryName', 'Tablets')
            ->set('editCategoryUnit', 'tablet')
            ->call('saveCategoryEdit')
            ->assertHasNoErrors()
            ->assertSet('showEditCategory', false);

        $category->refresh();
        $this->assertSame('Tablets', $category->name);
        $this->assertSame('tablet', $category->unit);
    }

    public function test_a_stock_category_with_items_cannot_be_deleted(): void
    {
        $category = \App\Models\StockCategory::factory()->create(['hospital_id' => $this->hospital->id]);
        \App\Models\StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'stock_category_id' => $category->id]);

        Livewire::test(\App\Livewire\Onboarding\Steps\Catalogues::class)->call('deleteCategory', $category->id)->assertDispatched('toast');

        $this->assertDatabaseHas('stock_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    // ── Step: the team ────────────────────────────────────────────────

    public function test_the_staff_step_invites_a_colleague_through_the_staff_service(): void
    {
        Mail::fake();

        Livewire::test(Staff::class)
            ->set('name', 'Grace Nurse')
            ->set('email', 'grace@example.test')
            ->set('role', 'nurse')
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated');

        $invited = User::where('email', 'grace@example.test')->firstOrFail();
        $this->assertSame($this->hospital->id, $invited->hospital_id);
        $this->assertTrue($invited->password_change_required, 'the invitee must set their own password');
        $this->assertTrue($this->checklist()->hasStaff($this->hospital));
    }

    public function test_the_staff_step_cannot_mint_a_super_admin(): void
    {
        Livewire::test(Staff::class)
            ->set('name', 'Mallory')
            ->set('email', 'mallory@example.test')
            ->set('role', 'super_admin')
            ->call('invite')
            ->assertHasErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'mallory@example.test']);
    }

    // ── Completing the whole thing ────────────────────────────────────

    public function test_completing_every_required_step_lifts_the_gate(): void
    {
        $this->actingAs(User::where('hospital_id', $this->hospital->id)->firstOrFail());

        // Blocked to begin with.
        $this->get(route('admin.patients.index'))->assertRedirect(route('admin.onboarding'));

        $this->completeProfile();
        Livewire::test(Billing::class)->call('save');
        Livewire::test(Departments::class)->call('addCommon');
        $services = Livewire::test(Services::class);
        $services->call('addStarter');
        Mail::fake();
        Livewire::test(Staff::class)
            ->set('name', 'Grace Nurse')->set('email', 'grace@example.test')->set('role', 'nurse')->call('invite');

        $this->assertTrue($this->checklist()->isComplete($this->hospital->fresh()));

        Livewire::test(Wizard::class)->call('finish')->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.patients.index'))->assertOk();
    }

    private function completeProfile(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'Demo Hospital')
            ->set('address', 'Plot 1')
            ->set('phone', '+256700000000')
            ->call('save');
    }
}
