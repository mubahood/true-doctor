<?php

namespace Tests\Feature;

use App\Enums\DentalTreatmentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Livewire\Patients\Tabs\Dental;
use App\Livewire\Services\Index as ServicesIndex;
use App\Livewire\Visits\Index as VisitsIndex;
use App\Livewire\Visits\Panels\OrderDetail;
use App\Livewire\Visits\Panels\Orders;
use App\Models\DentalChartEntry;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\DentalService;
use App\Services\OrderDesk;
use App\Services\OrderService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dental chart on the new visit form, the tooth dialog on the visit page,
 * and the chart following the work done: a completed order on named teeth
 * marks them by its service's "Marks the tooth as" (docs/dental.md).
 */
class DentalChartFollowsWorkTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $dentist;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        $this->hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->dentist = $this->staff('hospital_admin');
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'dob' => now()->subYears(30)->toDateString()]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function dental(): DentalService
    {
        return app(DentalService::class);
    }

    private function visit(): Visit
    {
        return Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id, 'status' => 'ongoing', 'doctor_user_id' => $this->dentist->id]);
    }

    private function service(string $name, ?string $mark = null): Service
    {
        return Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => $name, 'price' => '40000', 'marks_tooth_as' => $mark]);
    }

    // ── The new visit form ───────────────────────────────────────────────

    public function test_the_new_visit_form_shows_the_patients_chart_above_blood_pressure_and_saves_the_teeth_changed(): void
    {
        $this->dental()->record($this->patient, [['tooth' => 18, 'finding' => 'missing']], null, $this->dentist);

        $this->actingAs($this->dentist);
        $form = Livewire::test(VisitsIndex::class)->call('create')
            ->call('picked', 'patient_id', $this->patient->id)
            ->assertSee('Dental chart')
            ->assertSeeHtml('wire:click="pickTooth(36)"');

        $html = $form->html();
        $this->assertLessThan(strpos($html, 'BP mmHg'), strpos($html, 'Dental chart'), 'the chart comes before blood pressure');
        $this->assertSame('missing', $form->get('formChart')[18]['finding'] ?? null, 'the chart from earlier visits');

        // Tap, choose, OK: shown on the chart at once.
        $form->call('pickTooth', 36)->assertSet('showChartTooth', true)->assertSet('chartFinding', 'sound')
            ->set('chartFinding', 'caries')->call('applyTooth')
            ->assertSet('showChartTooth', false)
            ->assertSee('Changed here: 36 caries');
        // Cancel discards.
        $form->call('pickTooth', 46)->set('chartFinding', 'missing')->call('cancelTooth');
        // The same status a tooth already has is not written again.
        $form->call('pickTooth', 18)->assertSet('chartFinding', 'missing')->call('applyTooth');

        $form->set('blood_pressure', '120/80')->set('complaints', 'Pain')->call('save')->assertHasNoErrors();

        $visit = Visit::query()->latest('id')->firstOrFail();
        $entries = DentalChartEntry::query()->where('visit_id', $visit->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame(36, $entries->first()->tooth);
        $this->assertSame('caries', $this->dental()->chart($this->patient)[36]['finding']);
        $this->assertArrayNotHasKey(46, $this->dental()->chart($this->patient));
    }

    public function test_a_new_patient_starts_on_an_empty_chart_and_a_child_on_the_milk_teeth(): void
    {
        $this->dental()->record($this->patient, [['tooth' => 18, 'finding' => 'missing']], null, $this->dentist);

        $this->actingAs($this->dentist);
        $form = Livewire::test(VisitsIndex::class)->call('create')
            ->call('picked', 'patient_id', $this->patient->id)
            ->call('setTab', 'intake')
            ->assertSet('formChart', [])
            ->set('dob', now()->subYears(5)->toDateString())
            ->assertSet('chart_primary', true)
            ->assertSeeHtml('wire:click="pickTooth(55)"')
            ->call('pickTooth', 55)->set('chartFinding', 'mobility')->call('applyTooth')
            ->assertHasErrors('chartMobility')
            ->set('chartMobility', 2)->call('applyTooth')->assertHasNoErrors()
            ->set('first_name', 'Little')->set('last_name', 'One')
            ->call('save')->assertHasNoErrors();

        $child = Patient::query()->where('first_name', 'Little')->firstOrFail();
        $this->assertSame(2, $this->dental()->chart($child)[55]['mobility']);
        $this->assertSame(1, DentalChartEntry::query()->where('patient_id', $child->id)->count());
    }

    public function test_the_desk_sees_no_chart_on_the_form(): void
    {
        $this->actingAs($this->staff('receptionist'));
        Livewire::test(VisitsIndex::class)->call('create')->assertSee('Blood pressure')->assertDontSee('Tap a tooth');
    }

    public function test_a_general_hospitals_form_has_no_chart(): void
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        $this->assertFalse(\App\Support\Practice\VisitForm::of($general)->dentalClinic);
        $this->assertFalse(DentalService::teethOnOrders($general));
    }

    // ── The visit page: the same tap, dialog, OK ─────────────────────────

    public function test_the_visit_page_chart_opens_a_dialog_and_ok_records_cancel_does_not(): void
    {
        $visit = $this->visit();

        $tab = Livewire::actingAs($this->dentist)->test(Dental::class, ['patientId' => $this->patient->id, 'visitId' => $visit->id, 'part' => 'chart'])
            ->call('pick', 26)->assertSet('showTooth', true)->assertSee('Tooth 26')->assertSee('Root canal')
            ->set('finding', 'root_canal')->call('$set', 'showTooth', false);
        $this->assertSame(0, DentalChartEntry::count(), 'cancel records nothing');

        $tab->call('pick', 26)->set('finding', 'root_canal')->call('record')->assertSet('showTooth', false);
        $this->assertSame('root_canal', $this->dental()->chart($this->patient)[26]['finding']);
        $this->assertSame($visit->id, DentalChartEntry::query()->firstOrFail()->visit_id);

        // OK on the status it already has writes nothing.
        $tab->call('pick', 26)->assertSet('finding', 'root_canal')->call('record');
        $this->assertSame(1, DentalChartEntry::count());
    }

    // ── The chart follows the work ───────────────────────────────────────

    public function test_a_completed_procedure_order_marks_its_teeth_once(): void
    {
        $visit = $this->visit();
        $extraction = $this->service('Tooth extraction', 'missing');
        $orders = app(OrderService::class);

        $order = $orders->place($visit, OrderType::Procedure, 'Extraction', ['teeth' => [48, '38', 99, 38]], $this->dentist->id);
        $this->assertSame([38, 48], $order->fresh()->teeth, 'valid teeth, sorted, once');
        $orders->addServiceItem($order, $extraction, '1', $this->dentist->id);
        $this->assertSame(0, DentalChartEntry::count(), 'nothing charted before it is done');

        $orders->transition($order->fresh(), OrderStatus::Completed, $this->dentist->id);
        $chart = $this->dental()->chart($this->patient);
        $this->assertSame('missing', $chart[38]['finding']);
        $this->assertSame('missing', $chart[48]['finding']);
        $this->assertSame(2, DentalChartEntry::query()->where('order_id', $order->id)->where('visit_id', $visit->id)->count());

        // A line added after it was completed charts its own mark, and only that.
        $crown = $this->service('Crown (porcelain)', 'crown');
        $orders->addServiceItem($order->fresh(), $crown, '1', $this->dentist->id);
        $orders->addServiceItem($order->fresh(), $this->service('Scaling'), '1', $this->dentist->id);
        $this->dental()->chartOrder($order->fresh(), $this->dentist->id);
        $this->assertSame(4, DentalChartEntry::query()->where('order_id', $order->id)->count(), 'no duplicate on a repeat');
    }

    public function test_a_plan_item_done_marks_its_tooth_and_names_it_on_the_order(): void
    {
        $visit = $this->visit();
        $filling = $this->service('Dental filling', 'filled');
        $item = $this->dental()->planItem($this->patient, $filling, 36, 'MO', null, null, null, $this->dentist);

        $this->dental()->transition($item, DentalTreatmentStatus::Done, $this->dentist, $visit);
        $this->dental()->transition($item->fresh(), DentalTreatmentStatus::Done, $this->dentist, $visit);

        $chart = $this->dental()->chart($this->patient);
        $this->assertSame(['M' => 'filled', 'O' => 'filled'], $chart[36]['surfaces']);
        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame([36], $order->teeth);
        $this->assertSame(1, DentalChartEntry::count());

        // Completing that order later does not chart it again.
        app(OrderService::class)->transition($order, OrderStatus::Completed, $this->dentist->id);
        $this->assertSame(1, DentalChartEntry::count());
    }

    public function test_the_orders_panel_picks_teeth_shows_them_and_completing_charts(): void
    {
        $visit = $this->visit();
        $rct = $this->service('Root canal treatment', 'root_canal');
        $this->actingAs($this->dentist);

        Livewire::test(Orders::class, ['visitId' => $visit->id])
            ->call('openAdd')->set('type', 'procedure')
            ->assertSee('Teeth')
            ->set('title', 'Root canal')->set('teeth', ['46'])
            ->call('add')->assertHasNoErrors();
        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame([46], $order->teeth);
        app(OrderService::class)->addServiceItem($order, $rct, '1', $this->dentist->id);

        Livewire::withoutLazyLoading()->test(Orders::class, ['visitId' => $visit->id])->assertSee('Teeth 46');

        Livewire::withoutLazyLoading()->test(OrderDetail::class, ['visitId' => $visit->id])
            ->call('open', $visit->id, $order->id, true)
            ->assertSet('editTeeth', ['46'])
            ->set('editTeeth', ['46', '47'])->call('saveOrder')->assertHasNoErrors()
            ->call('open', $visit->id, $order->id)
            ->assertSee('46, 47')
            ->call('move', 'completed')
            ->assertDispatched('dental-changed');

        $chart = $this->dental()->chart($this->patient);
        $this->assertSame('root_canal', $chart[46]['finding']);
        $this->assertSame('root_canal', $chart[47]['finding']);
    }

    public function test_a_general_hospital_procedure_asks_for_no_teeth(): void
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        app(CurrentHospital::class)->set($general->id);
        $doctor = User::factory()->create(['hospital_id' => $general->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $patient = Patient::factory()->create(['hospital_id' => $general->id]);
        $visit = Visit::factory()->create(['hospital_id' => $general->id, 'patient_id' => $patient->id, 'status' => 'ongoing']);

        $this->actingAs($doctor);
        Livewire::test(Orders::class, ['visitId' => $visit->id])
            ->call('openAdd')->set('type', 'procedure')
            ->assertSee('Instructions')->assertDontSee('Tick each tooth');
        $this->assertFalse(OrderDesk::form()['teeth']);
    }

    public function test_the_api_places_and_changes_an_order_with_teeth(): void
    {
        $visit = $this->visit();
        $this->service('Tooth extraction', 'missing');
        Sanctum::actingAs($this->dentist);

        $this->postJson("/api/v1/visits/{$visit->uuid}/orders", ['type' => 'procedure', 'title' => 'Extraction', 'teeth' => [19]])
            ->assertStatus(422)->assertJsonValidationErrors('teeth.0');
        $this->postJson("/api/v1/visits/{$visit->uuid}/orders", ['type' => 'procedure', 'title' => 'Extraction', 'teeth' => [38]], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated();
        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();

        $this->patchJson("/api/v1/orders/{$order->uuid}", ['teeth' => [38, 48]])
            ->assertOk()->assertJsonPath('data.teeth', [38, 48]);
        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk()->assertJsonPath('data.teeth', [38, 48]);
    }

    // ── The price list ───────────────────────────────────────────────────

    public function test_the_price_list_sets_what_a_service_marks_and_a_new_dental_service_is_guessed(): void
    {
        $this->assertSame('missing', Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Surgical extraction', 'category' => 'Dental'])->marks_tooth_as);
        $this->assertNull(Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Scaling and polishing', 'category' => 'Dental'])->marks_tooth_as);
        $this->assertNull(Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Wound dressing'])->marks_tooth_as);

        $this->actingAs($this->dentist);
        Livewire::test(ServicesIndex::class)->call('create')
            ->assertSee('Marks the tooth as')
            ->set('name', 'Porcelain crown')->set('price', '600000')->set('marks_tooth_as', 'crown')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('crown', Service::query()->where('name', 'Porcelain crown')->value('marks_tooth_as'));

        Livewire::test(ServicesIndex::class)->call('create')
            ->set('name', 'Odd')->set('price', '1')->set('marks_tooth_as', 'caries')
            ->call('save')->assertHasErrors('marks_tooth_as');
    }
}
