<?php

namespace Tests\Feature\Livewire;

use App\Enums\BedStatus;
use App\Livewire\Ui\SelectSearch;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Ward;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shared async picker (C4/D3/L1). Guarantees: results are searched and
 * capped, never cross a tenant boundary, and a pick is reported to the parent
 * as `select-search:picked`.
 */
class SelectSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingAdmin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_it_searches_patients_and_reports_the_pick(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $hit = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Findable', 'last_name' => 'Person']);
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Hiddenone', 'last_name' => 'Person']);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->set('query', 'Findable')
            ->assertSee('Findable')
            ->assertDontSee('Hiddenone')
            ->call('pick', $hit->id)
            ->assertSet('selected', $hit->id)
            ->assertSet('query', '')
            ->assertSee('Findable Person')
            ->assertDispatched('select-search:picked', name: 'patient_id', id: $hit->id);
    }

    public function test_enter_picks_the_first_result_and_escape_clears_the_query(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $first = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Aaron', 'last_name' => 'Alpha']);
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Zed', 'last_name' => 'Alpha']);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->set('query', 'Alpha')
            ->call('pickFirst')
            ->assertSet('selected', $first->id);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->set('query', 'Alpha')
            ->call('clearQuery')
            ->assertSet('query', '')
            ->assertSet('selected', null);
    }

    public function test_results_never_cross_a_tenant_boundary(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $theirs = Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Foreign', 'last_name' => 'Patient']);
        $this->actingAdmin($a);

        $component = Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->set('query', 'Foreign')
            ->assertDontSee('Foreign Patient');

        // Even a hand-crafted id from another hospital is refused.
        $component->call('pick', $theirs->id)
            ->assertSet('selected', null)
            ->assertNotDispatched('select-search:picked');
    }

    public function test_a_preselected_id_resolves_its_label_but_a_foreign_one_does_not(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $mine = Patient::factory()->create(['hospital_id' => $a->id, 'first_name' => 'Mine', 'last_name' => 'Own']);
        $theirs = Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Theirs', 'last_name' => 'Own']);
        $this->actingAdmin($a);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id', 'selected' => $mine->id])
            ->assertSet('selectedLabel', 'Mine Own');

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id', 'selected' => $theirs->id])
            ->assertSet('selectedLabel', null);
    }

    public function test_beds_picker_only_lists_available_active_beds(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $ward = Ward::factory()->create(['hospital_id' => $h->id, 'name' => 'Maternity']);
        Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'FreeBed', 'status' => BedStatus::Available->value, 'is_active' => true]);
        Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'BusyBed', 'status' => BedStatus::Occupied->value, 'is_active' => true]);

        Livewire::test(SelectSearch::class, ['resource' => 'beds-available', 'name' => 'bed_id'])
            ->set('query', 'Bed')
            ->assertSee('FreeBed')
            ->assertDontSee('BusyBed');
    }

    public function test_providers_picker_only_lists_active_providers(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        InsuranceProvider::factory()->create(['hospital_id' => $h->id, 'name' => 'LiveCover', 'is_active' => true]);
        InsuranceProvider::factory()->create(['hospital_id' => $h->id, 'name' => 'DeadCover', 'is_active' => false]);

        Livewire::test(SelectSearch::class, ['resource' => 'providers', 'name' => 'insurance_provider_id'])
            ->set('query', 'Cover')
            ->assertSee('LiveCover')
            ->assertDontSee('DeadCover');
    }

    public function test_stock_items_picker_only_lists_active_items_with_stock(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        $inStock = StockItem::factory()->create([
            'hospital_id' => $h->id, 'name' => 'Amoxicillin', 'unit' => 'tabs',
            'is_active' => true, 'current_quantity' => '40.00', 'sale_price' => '2.00',
        ]);
        StockItem::factory()->create([
            'hospital_id' => $h->id, 'name' => 'Amoxidry', 'is_active' => true, 'current_quantity' => '0.00',
        ]);
        StockItem::factory()->create([
            'hospital_id' => $h->id, 'name' => 'Amoxiretired', 'is_active' => false, 'current_quantity' => '10.00',
        ]);

        Livewire::test(SelectSearch::class, ['resource' => 'stock-items', 'name' => 'stock_item_id'])
            ->set('query', 'Amoxi')
            ->assertSee('Amoxicillin')
            ->assertSee('40 tabs')                                  // on-hand in the meta line
            ->assertSee(\App\Support\HospitalSettings::money('2.00'))
            ->assertDontSee('Amoxidry')
            ->assertDontSee('Amoxiretired')
            ->call('pick', $inStock->id)
            ->assertSet('selected', $inStock->id)
            ->assertDispatched('select-search:picked', name: 'stock_item_id', id: $inStock->id);
    }

    /** The price list. Inactive entries are ones the hospital stopped offering. */
    public function test_services_picker_only_lists_what_is_still_offered(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        $offered = Service::factory()->create([
            'hospital_id' => $h->id, 'name' => 'Consultation', 'code' => 'CONS', 'price' => '8000', 'is_active' => true,
        ]);
        Service::factory()->create([
            'hospital_id' => $h->id, 'name' => 'Consultation (old)', 'price' => '5000', 'is_active' => false,
        ]);

        Livewire::test(SelectSearch::class, ['resource' => 'services', 'name' => 'service_id'])
            ->set('query', 'Consult')
            ->assertSee('Consultation')
            ->assertSee('CONS')
            ->assertSee(\App\Support\HospitalSettings::money('8000'))
            ->assertDontSee('Consultation (old)')
            ->call('pick', $offered->id)
            ->assertSet('selected', $offered->id)
            ->assertDispatched('select-search:picked', name: 'service_id', id: $offered->id);
    }

    public function test_services_never_cross_a_tenant_boundary(): void
    {
        $mine = Hospital::factory()->create();
        $theirs = Hospital::factory()->create();

        app(CurrentHospital::class)->set($theirs->id);
        $hidden = Service::factory()->create([
            'hospital_id' => $theirs->id, 'name' => 'Zzz Secret Service', 'is_active' => true,
        ]);

        $this->actingAdmin($mine);

        Livewire::test(SelectSearch::class, ['resource' => 'services', 'name' => 'service_id'])
            ->set('query', 'Zzz')
            ->assertDontSee('Zzz Secret Service')
            ->call('pick', $hidden->id)
            ->assertSet('selected', null);
    }

    public function test_stock_items_never_cross_a_tenant_boundary(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $theirs = StockItem::factory()->create([
            'hospital_id' => $b->id, 'name' => 'ForeignDrug', 'is_active' => true, 'current_quantity' => '10.00',
        ]);
        $this->actingAdmin($a);

        Livewire::test(SelectSearch::class, ['resource' => 'stock-items', 'name' => 'stock_item_id'])
            ->set('query', 'Foreign')
            ->assertDontSee('ForeignDrug')
            ->call('pick', $theirs->id)
            ->assertSet('selected', null)
            ->assertNotDispatched('select-search:picked');
    }

    // ── Suggestions: the likely picks, before anyone types ───────────────

    /** The person signed in is the first thing offered on a staff picker. */
    public function test_the_staff_picker_suggests_the_signed_in_user_first(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->actingAdmin($h);
        User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse', 'is_active' => true]);

        $suggestions = Livewire::test(SelectSearch::class, ['resource' => 'staff', 'name' => 'assigned_to'])
            ->get('suggestions');

        $this->assertNotEmpty($suggestions);
        $this->assertSame($me->id, $suggestions[0]['id'], 'the signed-in user is not offered first');
    }

    /** Suggestions are for choosing FROM, so they stop once a choice is made. */
    public function test_suggestions_stop_once_something_is_picked_or_typed(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->actingAdmin($h);

        Livewire::test(SelectSearch::class, ['resource' => 'staff', 'name' => 'assigned_to'])
            ->assertCount('suggestions', 1)
            ->set('query', 'anything')
            ->assertCount('suggestions', 0)
            ->set('query', '')
            ->call('pick', $me->id)
            ->assertCount('suggestions', 0);
    }

    /** A suggestion can no more cross a hospital than a search result can. */
    public function test_suggestions_never_cross_a_tenant_boundary(): void
    {
        $mine = Hospital::factory()->create();
        $theirs = Hospital::factory()->create();
        User::factory()->create(['hospital_id' => $theirs->id, 'role' => 'nurse', 'is_active' => true]);

        $me = $this->actingAdmin($mine);

        $ids = array_column(
            Livewire::test(SelectSearch::class, ['resource' => 'staff', 'name' => 'assigned_to'])->get('suggestions'),
            'id',
        );

        $this->assertSame([$me->id], $ids);
    }

    /** A picker with no useful signal simply offers nothing. */
    public function test_a_resource_with_no_signal_offers_no_suggestions(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(SelectSearch::class, ['resource' => 'beds-available', 'name' => 'bed_id'])
            ->assertCount('suggestions', 0);
    }

    public function test_an_unknown_resource_is_refused(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(SelectSearch::class, ['resource' => 'users; drop table', 'name' => 'patient_id'])
            ->assertStatus(404);
    }

    // ── Focus shows what there is ────────────────────────────────────────

    /**
     * Nobody should have to guess a name to find out what is on offer.
     *
     * Focus opens a capped list; it is still one query and still bounded,
     * unlike the whole-table <select> this component replaced.
     */
    public function test_focus_opens_a_list_before_anything_is_typed(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        foreach (['Aspirin', 'Brufen', 'Ciprofloxacin'] as $name) {
            StockItem::factory()->create([
                'hospital_id' => $h->id, 'name' => $name, 'is_active' => true,
                'current_quantity' => '10.00', 'sale_price' => '1000',
            ]);
        }

        $picker = Livewire::test(SelectSearch::class, ['resource' => 'stock-items', 'name' => 'stock_item_id']);

        // Closed: nothing but the pills.
        $picker->assertSet('open', false)->assertDontSee('Ciprofloxacin');

        $picker->call('openList')
            ->assertSet('open', true)
            ->assertSee('Aspirin')
            ->assertSee('Brufen')
            ->assertSee('Ciprofloxacin');
    }

    /** The likely picks lead it, and are not then repeated below. */
    public function test_the_list_leads_with_the_likely_picks_and_does_not_repeat_them(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->actingAdmin($h);

        $browse = Livewire::test(SelectSearch::class, ['resource' => 'staff', 'name' => 'assigned_to'])
            ->call('openList')
            ->get('browse');

        $this->assertNotEmpty($browse['likely']);
        $this->assertSame($me->id, $browse['likely'][0]['id'], 'the signed-in user does not lead the list');

        $overlap = array_intersect(
            array_column($browse['likely'], 'id'),
            array_column($browse['rest'], 'id'),
        );
        $this->assertSame([], array_values($overlap), 'a row was offered twice');
    }

    /** It is capped. A picker is for finding, not for shipping a table. */
    public function test_the_list_is_capped(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Patient::factory()->count(70)->create(['hospital_id' => $h->id]);

        $browse = Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->call('openList')
            ->get('browse');

        $this->assertLessThanOrEqual(50, count($browse['likely']) + count($browse['rest']));
    }

    /** Typing switches it from browsing to searching. */
    public function test_typing_narrows_the_open_list(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Findable']);
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Hiddenone']);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->call('openList')
            ->assertSee('Hiddenone')
            ->set('query', 'Findable')
            ->assertSee('Findable')
            ->assertDontSee('Hiddenone');
    }

    /** Picking closes it; the list has done its job. */
    public function test_picking_closes_the_list(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ada']);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->call('openList')
            ->call('pick', $patient->id)
            ->assertSet('open', false);
    }

    /** Escape empties the box, then closes the list. */
    public function test_escape_empties_then_closes(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->call('openList')
            ->set('query', 'Ada')
            ->call('clearQuery')
            ->assertSet('query', '')
            ->assertSet('open', true, 'the first press closed the list instead of emptying the box')
            ->call('clearQuery')
            ->assertSet('open', false);
    }

    /** Changing a pick goes straight back to a list, not to an empty box. */
    public function test_changing_a_pick_reopens_the_list(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(SelectSearch::class, [
            'resource' => 'patients', 'name' => 'patient_id', 'selected' => $patient->id,
        ])
            ->call('change')
            ->assertSet('open', true)
            ->assertSet('selected', null);
    }

    /** A tenant boundary holds when browsing exactly as it does when searching. */
    public function test_the_list_never_crosses_a_tenant_boundary(): void
    {
        $mine = Hospital::factory()->create();
        $theirs = Hospital::factory()->create();

        app(CurrentHospital::class)->set($theirs->id);
        Patient::factory()->create(['hospital_id' => $theirs->id, 'first_name' => 'Zzzsecret']);

        $this->actingAdmin($mine);

        Livewire::test(SelectSearch::class, ['resource' => 'patients', 'name' => 'patient_id'])
            ->call('openList')
            ->assertDontSee('Zzzsecret');
    }
}
