<?php

namespace Tests\Feature;

use App\Enums\DentalTreatmentStatus;
use App\Livewire\Patients\Tabs\Dental;
use App\Models\DentalChartEntry;
use App\Models\DentalTreatmentItem;
use App\Models\Hospital;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\DentalService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** The dental chart, the treatment plan, its billing and the recall (roadmap 04 §3). */
class DentalTest extends TestCase
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
        $this->dentist = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $this->dentist->syncSpatieRole();
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function dental(): DentalService
    {
        return app(DentalService::class);
    }

    private function openVisit(): Visit
    {
        return Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id]);
    }

    public function test_fdi_teeth_and_surfaces(): void
    {
        $this->assertCount(32, DentalService::teeth());
        $this->assertCount(20, DentalService::teeth(true));
        $this->assertTrue(DentalService::validTooth(36));
        $this->assertTrue(DentalService::validTooth(85));
        $this->assertFalse(DentalService::validTooth(19));
        $this->assertFalse(DentalService::validTooth(56));
        $this->assertSame('MOD', DentalService::normaliseSurfaces('d,o,m'));
        $this->assertNull(DentalService::normaliseSurfaces('xyz'));
    }

    public function test_the_chart_is_the_latest_finding_per_tooth_and_surface_and_history_is_kept(): void
    {
        $this->dental()->record($this->patient, [['tooth' => 36, 'finding' => 'caries', 'surfaces' => 'MO']], null, $this->dentist);
        $this->travel(1)->days();
        $this->dental()->record($this->patient, [['tooth' => 36, 'finding' => 'filled', 'surfaces' => 'M'], ['tooth' => 18, 'finding' => 'missing']], null, $this->dentist);

        $chart = $this->dental()->chart($this->patient);

        $this->assertSame(['M' => 'filled', 'O' => 'caries'], $chart[36]['surfaces']);
        $this->assertSame('missing', $chart[18]['finding']);
        $this->assertSame(3, DentalChartEntry::count(), 'every finding kept, nothing overwritten');

        $yesterday = $this->dental()->chart($this->patient, now()->subDay()->endOfDay());
        $this->assertSame(['M' => 'caries', 'O' => 'caries'], $yesterday[36]['surfaces'], 'the chart as it was');
        $this->assertArrayNotHasKey(18, $yesterday);
    }

    public function test_sound_clears_a_tooth_and_a_whole_tooth_finding_ignores_surfaces(): void
    {
        $this->dental()->record($this->patient, [['tooth' => 21, 'finding' => 'caries', 'surfaces' => 'I']], null, $this->dentist);
        $this->dental()->record($this->patient, [['tooth' => 21, 'finding' => 'crown', 'surfaces' => 'MOD']], null, $this->dentist);
        $this->assertSame('crown', $this->dental()->chart($this->patient)[21]['finding']);
        $this->assertSame([], $this->dental()->chart($this->patient)[21]['surfaces']);

        $this->dental()->record($this->patient, [['tooth' => 21, 'finding' => 'sound']], null, $this->dentist);
        $this->assertNull($this->dental()->chart($this->patient)[21]['finding']);
    }

    public function test_a_bad_tooth_or_a_missing_grade_is_refused_and_a_repeated_uuid_is_charted_once(): void
    {
        try {
            $this->dental()->record($this->patient, [['tooth' => 99, 'finding' => 'caries'], ['tooth' => 31, 'finding' => 'mobility']], null, $this->dentist);
            $this->fail('should refuse');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('findings.0.tooth', $e->errors());
            $this->assertArrayHasKey('findings.1.mobility', $e->errors());
        }

        $uuid = (string) Str::uuid();
        $this->dental()->record($this->patient, [['uuid' => $uuid, 'tooth' => 31, 'finding' => 'caries', 'surfaces' => 'O']], null, $this->dentist);
        $this->dental()->record($this->patient, [['uuid' => $uuid, 'tooth' => 31, 'finding' => 'caries', 'surfaces' => 'O']], null, $this->dentist);
        $this->assertSame(1, DentalChartEntry::count());
    }

    public function test_a_planned_procedure_takes_a_price_inside_its_range(): void
    {
        $extraction = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Difficult extraction', 'price' => '50000', 'max_price' => '80000']);

        $item = $this->dental()->planItem($this->patient, $extraction, 38, null, 65000, null, null, $this->dentist);
        $this->assertSame('65000.00', (string) $item->price);

        $this->expectException(ValidationException::class);
        $this->dental()->planItem($this->patient, $extraction, 38, null, 90000, null, null, $this->dentist);
    }

    public function test_done_bills_once_on_the_open_visit_and_never_twice(): void
    {
        $filling = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Composite filling', 'price' => '60000']);
        $visit = $this->openVisit();
        $item = $this->dental()->planItem($this->patient, $filling, 36, 'MO', null, null, null, $this->dentist);

        $done = $this->dental()->transition($item, DentalTreatmentStatus::Done, $this->dentist, $visit);
        $again = $this->dental()->transition($item->fresh(), DentalTreatmentStatus::Done, $this->dentist, $visit);

        $this->assertSame(DentalTreatmentStatus::Done, $done->status);
        $this->assertNotNull($done->order_item_id);
        $this->assertSame($done->order_item_id, $again->order_item_id);
        $line = OrderItem::query()->findOrFail($done->order_item_id);
        $this->assertSame('60000.00', (string) $line->unit_price);
        $this->assertSame(1, OrderItem::query()->where('service_id', $filling->id)->count(), 'billed exactly once');
        $this->assertSame($visit->id, $done->done_visit_id);
        $this->assertSame('Composite filling (36 MO)', $this->dental()->title($done));
    }

    public function test_done_without_an_open_visit_says_open_one_and_a_finished_item_cannot_move(): void
    {
        $filling = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '60000']);
        $item = $this->dental()->planItem($this->patient, $filling, 36, null, null, null, null, $this->dentist);

        try {
            $this->dental()->transition($item, DentalTreatmentStatus::Done, $this->dentist);
            $this->fail('needs a visit');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Open a visit', $e->errors()['visit'][0]);
        }

        $this->dental()->transition($item, DentalTreatmentStatus::Cancelled, $this->dentist);
        $this->expectException(ValidationException::class);
        $this->dental()->transition($item->fresh(), DentalTreatmentStatus::Accepted, $this->dentist);
    }

    public function test_the_quote_totals_what_is_still_to_do(): void
    {
        $a = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '60000']);
        $b = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '150000']);
        $this->dental()->planItem($this->patient, $a, 36, null, null, null, null, $this->dentist);
        $cancelled = $this->dental()->planItem($this->patient, $b, 46, null, null, null, null, $this->dentist);
        $this->dental()->transition($cancelled, DentalTreatmentStatus::Cancelled, $this->dentist);

        $this->assertSame('60000.00', $this->dental()->quote($this->patient)['total']);
        $this->actingAs($this->dentist)->get(route('admin.patients.dental-quote', $this->patient))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_recall_defaults_to_six_months_and_cannot_be_in_the_past(): void
    {
        $this->dental()->setRecall($this->patient, DentalService::defaultRecall());
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $this->patient->fresh()->next_recall_on->toDateString());

        $this->expectException(ValidationException::class);
        $this->dental()->setRecall($this->patient, now()->subDays(3));
    }

    public function test_another_hospital_never_sees_this_chart(): void
    {
        $this->dental()->record($this->patient, [['tooth' => 11, 'finding' => 'caries', 'surfaces' => 'I']], null, $this->dentist);

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);

        $this->assertSame(0, DentalChartEntry::count());
        $this->assertSame(0, DentalTreatmentItem::count());
    }

    public function test_the_api_charts_plans_and_bills(): void
    {
        $filling = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '60000']);
        $visit = $this->openVisit();
        \Laravel\Sanctum\Sanctum::actingAs($this->dentist);

        $this->postJson("/api/v1/patients/{$this->patient->uuid}/dental/findings", ['findings' => [['tooth' => 36, 'finding' => 'caries', 'surfaces' => 'MO']]])
            ->assertCreated()->assertJsonPath('data.chart.0.tooth', 36);
        $uuid = $this->postJson("/api/v1/patients/{$this->patient->uuid}/dental/plan", ['service_id' => $filling->id, 'tooth' => 36, 'surfaces' => 'MO'])
            ->assertCreated()->json('data.uuid');
        $this->postJson("/api/v1/dental/plan/{$uuid}/status", ['status' => 'done', 'visit_uuid' => $visit->uuid], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.billed', true);

        $this->getJson("/api/v1/patients/{$this->patient->uuid}/dental")->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.plan.0.status', 'done');
        $this->putJson("/api/v1/patients/{$this->patient->uuid}/recall", ['next_recall_on' => 'default'])->assertOk();
        $this->getJson('/api/v1/dental/report')->assertOk()->assertJsonPath('data.by_procedure.0.count', 1);
    }

    public function test_a_nurse_sees_but_does_not_chart(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        \Laravel\Sanctum\Sanctum::actingAs($nurse);

        $this->getJson("/api/v1/patients/{$this->patient->uuid}/dental")->assertOk();
        $this->postJson("/api/v1/patients/{$this->patient->uuid}/dental/findings", ['findings' => [['tooth' => 36, 'finding' => 'caries']]])->assertForbidden();
    }

    public function test_the_dental_tab_charts_a_tooth_and_plans_on_it(): void
    {
        $filling = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Amalgam filling', 'price' => '40000']);

        Livewire::actingAs($this->dentist)->test(Dental::class, ['patientId' => $this->patient->id])
            ->assertSee('Dental chart')
            ->call('pick', 46)
            ->set('surfaces', ['O'])->set('finding', 'caries')
            ->call('record')
            ->assertHasNoErrors()
            ->call('openPlan', 46)
            ->set('serviceId', $filling->id)
            ->call('addToPlan')
            ->assertSee('Amalgam filling');

        $this->assertSame('caries', $this->dental()->chart($this->patient)[46]['surfaces']['O']);
    }

    public function test_a_child_opens_on_the_milk_teeth_and_an_adult_on_the_permanent_ones(): void
    {
        $child = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'dob' => now()->subYears(6)->toDateString()]);

        Livewire::actingAs($this->dentist)->test(Dental::class, ['patientId' => $child->id])
            ->assertSet('primary', true)->assertSeeHtml('wire:key="tooth-55"')->assertDontSeeHtml('wire:key="tooth-18"');

        Livewire::actingAs($this->dentist)->test(Dental::class, ['patientId' => $this->patient->id])
            ->assertSet('primary', false);
    }

    public function test_the_patient_page_offers_the_dental_tab_only_in_a_dental_clinic(): void
    {
        $this->actingAs($this->dentist)->get(route('admin.patients.show', $this->patient))->assertSee('ptab-dental', false);

        $clinic = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'medical_clinic']]]);
        app(CurrentHospital::class)->set($clinic->id);
        $doctor = User::factory()->create(['hospital_id' => $clinic->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $patient = Patient::factory()->create(['hospital_id' => $clinic->id]);
        $this->actingAs($doctor)->get(route('admin.patients.show', $patient))->assertDontSee('ptab-dental', false);
    }

    public function test_a_general_hospital_shows_the_dental_tab_only_where_there_is_dentistry(): void
    {
        $hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        app(CurrentHospital::class)->set($hospital->id);
        $doctor = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $patient = Patient::factory()->create(['hospital_id' => $hospital->id]);

        // No dentist, nothing charted: no tab, on the web or in the app.
        $this->assertFalse(DentalService::onPatient($patient, $hospital, $doctor));
        \Laravel\Sanctum\Sanctum::actingAs($doctor);
        $this->assertNotContains('dental', $this->getJson("/api/v1/patients/{$patient->uuid}/summary")->assertOk()->json('data.sections'));

        // A patient already charted keeps the tab.
        $this->dental()->record($patient, [['tooth' => 11, 'finding' => 'caries', 'surfaces' => 'M']], null, $doctor);
        $this->assertTrue(DentalService::onPatient($patient, $hospital, $doctor));

        // A hospital with a dental department shows it on everybody.
        $other = Patient::factory()->create(['hospital_id' => $hospital->id]);
        \App\Models\Department::factory()->create(['hospital_id' => $hospital->id, 'name' => 'Dental unit', 'is_active' => true]);
        $this->assertTrue(DentalService::onPatient($other, $hospital, $doctor));
        $this->assertContains('dental', $this->getJson("/api/v1/patients/{$other->uuid}/summary")->assertOk()->json('data.sections'));
    }

    public function test_the_app_prints_the_quote(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->dentist);
        $this->get("/api/v1/patients/{$this->patient->uuid}/dental/quote")->assertOk()->assertHeader('content-type', 'application/pdf');

        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $nurse->syncSpatieRole();
        \Laravel\Sanctum\Sanctum::actingAs($nurse);
        $this->get("/api/v1/patients/{$this->patient->uuid}/dental/quote")->assertForbidden();
    }

    public function test_a_hospital_with_the_dental_pack_charts_on_a_visit_only_when_it_is_dental_work(): void
    {
        // A dental clinic: every visit opens on the chart.
        $this->assertTrue(DentalService::chartOnVisit($this->openVisit(), $this->hospital));

        $hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital', 'packs' => ['dental']]]]);
        app(CurrentHospital::class)->set($hospital->id);
        $doctor = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $patient = Patient::factory()->create(['hospital_id' => $hospital->id]);
        $outpatient = \App\Models\Department::factory()->create(['hospital_id' => $hospital->id, 'name' => 'Outpatient']);
        $dentistry = \App\Models\Department::factory()->create(['hospital_id' => $hospital->id, 'name' => 'Dental']);
        $malaria = Visit::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'department_id' => $outpatient->id]);

        $this->assertFalse(DentalService::chartOnVisit($malaria, $hospital));
        $this->assertTrue(DentalService::chartOnVisit(Visit::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'department_id' => $dentistry->id]), $hospital));

        // The malaria visit offers the chart on a press, and opens it.
        Livewire::actingAs($doctor)->test(\App\Livewire\Visits\Show::class, ['visit' => $malaria])
            ->assertSee('Open the chart')->assertDontSeeHtml('wire:key="tooth-11"')
            ->set('dentalOpen', true)
            ->assertDontSee('Open the chart');

        // Once the mouth is charted, every later visit opens on it.
        $this->dental()->record($patient, [['tooth' => 11, 'finding' => 'caries', 'surfaces' => 'M']], null, $doctor);
        $this->assertTrue(DentalService::chartOnVisit($malaria->fresh(), $hospital));
    }

    public function test_an_offline_finding_syncs_once(): void
    {
        $handler = app(\App\Services\Sync\Handlers\DentalChartHandler::class);
        $op = new \App\Models\SyncOperation(['device_id' => null, 'operation_id' => (string) Str::uuid()]);
        $payload = ['uuid' => (string) Str::uuid(), 'patient_uuid' => $this->patient->uuid, 'tooth' => 26, 'finding' => 'filled', 'surfaces' => 'O'];

        $this->assertSame('accepted', $handler->apply('create', $payload, $op, $this->dentist)['status']);
        $this->assertSame('accepted', $handler->apply('create', $payload, $op, $this->dentist)['status']);
        $this->assertSame('append_only', $handler->apply('update', $payload, $op, $this->dentist)['reason_code']);
        $this->assertSame(1, DentalChartEntry::count());
    }
}
