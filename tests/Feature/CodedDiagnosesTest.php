<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Services\DiagnosisService;
use App\Services\Hmis\HmisReports;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Coded diagnoses (roadmap 04 §1) and the Ministry reports built on them (§2). */
class CodedDiagnosesTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();
        $this->h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->h->id);
        $this->doctor = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor']);
        $this->doctor->syncSpatieRole();
    }

    private function dx(): DiagnosisService
    {
        return app(DiagnosisService::class);
    }

    private function visit(array $patient = [], ?string $at = null): Visit
    {
        $p = Patient::factory()->create($patient + ['hospital_id' => $this->h->id, 'sex' => 'female', 'dob' => '1990-01-01']);

        return Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $p->id, 'created_at' => $at ?? now()]);
    }

    public function test_the_catalogue_is_loaded_and_found_in_plain_words_in_three_languages(): void
    {
        $this->assertGreaterThan(300, \App\Models\DiagnosisCode::query()->count());
        $this->assertSame('B50.9', $this->dx()->search('B50.9')->first()->code);
        $this->assertContains('B50.9', $this->dx()->search('omusujja')->pluck('code')->all());
        $this->assertContains('I10', $this->dx()->search('shinikizo la damu')->pluck('code')->all());
        $this->assertContains('I10', $this->dx()->search('high blood pressure')->pluck('code')->all());
    }

    public function test_a_suggestion_reads_the_whole_written_diagnosis(): void
    {
        $this->assertSame('B50.9', $this->dx()->suggest('Uncomplicated malaria, confirmed by rapid diagnostic test.')->first()->code);
        $this->assertSame('I10', $this->dx()->suggest('Hypertension, adequately controlled on current dose.')->first()->code);
    }

    public function test_the_practice_own_most_used_come_first(): void
    {
        foreach (range(1, 3) as $i) {
            $this->dx()->add($this->visit(), 'B54', true, 'confirmed', null, $this->doctor);
        }
        $this->assertSame('B54', $this->dx()->search('malaria', $this->h->id)->first()->code);
    }

    public function test_a_visit_has_one_primary_and_every_change_is_a_new_row(): void
    {
        $v = $this->visit();
        $a = $this->dx()->add($v, 'B50.9', false, 'confirmed', null, $this->doctor);
        $this->assertTrue($a->is_primary, 'the first diagnosis is the primary');
        $b = $this->dx()->add($v, 'D50.9', true, 'suspected', null, $this->doctor);

        $current = $this->dx()->current($v);
        $this->assertSame(['D50.9', 'B50.9'], $current->pluck('code')->all());
        $this->assertSame(1, $current->where('is_primary', true)->count());

        $this->dx()->remove($current->firstWhere('code', 'D50.9'), $this->doctor);
        $this->assertSame(['B50.9'], $this->dx()->current($v)->pluck('code')->all());
        $this->assertTrue($this->dx()->current($v)->first()->is_primary, 'the next one becomes primary');
        $this->assertGreaterThanOrEqual(5, VisitDiagnosis::query()->count(), 'nothing was edited or deleted');

        $this->expectException(ValidationException::class);
        $this->dx()->add($v, 'NOT-A-CODE', false);
    }

    public function test_the_visit_panel_codes_with_one_tap_and_a_nurse_cannot(): void
    {
        $v = $this->visit();
        $v->forceFill(['diagnosis' => 'Uncomplicated malaria, confirmed by RDT'])->save();

        Livewire::actingAs($this->doctor)->test(\App\Livewire\Visits\Panels\Diagnoses::class, ['visitId' => $v->id])
            ->assertSee('From the written diagnosis')
            ->set('q', 'omusujja')->assertSee('B50.9')
            ->call('add', 'B50.9')->assertSee('Primary');
        $this->assertSame('B50.9', $this->dx()->current($v)->first()->code);

        $nurse = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        Livewire::actingAs($nurse)->test(\App\Livewire\Visits\Panels\Diagnoses::class, ['visitId' => $v->id])
            ->assertDontSee('Type the diagnosis')->call('add', 'B54')->assertForbidden();
    }

    public function test_the_worklist_offers_codes_for_written_diagnoses_and_a_person_chooses(): void
    {
        $v = $this->visit();
        $v->forceFill(['diagnosis' => 'Hypertension, adequately controlled'])->save();
        $records = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'records_officer']);
        $records->syncSpatieRole();

        Livewire::actingAs($records)->test(\App\Livewire\Diagnoses\Worklist::class)
            ->assertSee($v->visit_no)->assertSee('I10')
            ->call('code', $v->id, 'I10')
            ->assertDontSee($v->visit_no);
        $this->assertSame('I10', $this->dx()->current($v)->first()->code);
    }

    public function test_the_api_and_an_offline_device_code_the_same_way(): void
    {
        $v = $this->visit();
        \Laravel\Sanctum\Sanctum::actingAs($this->doctor);
        $this->getJson('/api/v1/diagnoses/search?q=asthma')->assertOk()->assertJsonPath('data.0.code', 'J45.9');
        $id = $this->postJson("/api/v1/visits/{$v->uuid}/diagnoses", ['code' => 'J45.9'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/visits/{$v->uuid}/diagnoses")->assertOk()->assertJsonPath('data.0.primary', true);

        $uuid = (string) Str::uuid();
        $handler = app(\App\Services\Sync\Handlers\VisitDiagnosisHandler::class);
        $op = new \App\Models\SyncOperation(['device_id' => null, 'operation_id' => 'op-dx']);
        $first = $handler->apply('create', ['uuid' => $uuid, 'visit_uuid' => $v->uuid, 'code' => 'R05'], $op, $this->doctor);
        $again = $handler->apply('create', ['uuid' => $uuid, 'visit_uuid' => $v->uuid, 'code' => 'R05'], $op, $this->doctor);
        $this->assertSame('accepted', $first['status']);
        $this->assertSame($first['server_id'], $again['server_id'], 'a replay codes it once');

        $this->deleteJson("/api/v1/visits/{$v->uuid}/diagnoses/{$id}")->assertOk();
        $this->assertSame(['R05'], $this->dx()->current($v)->pluck('code')->all());
    }

    public function test_hmis_105_counts_by_band_and_sex_and_lists_what_it_cannot_count(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $adult = $this->visit(['sex' => 'female', 'dob' => '1990-05-01'], '2026-09-10 09:00:00');
        $child = $this->visit(['sex' => 'male', 'dob' => '2023-01-01'], '2026-09-11 09:00:00');
        $uncoded = $this->visit(['sex' => 'male', 'dob' => '2000-01-01'], '2026-09-12 09:00:00');
        $noAge = $this->visit(['sex' => 'male', 'dob' => null], '2026-09-13 09:00:00');
        $this->dx()->add($adult, 'B50.9', true);
        $this->dx()->add($child, 'B50.9', true);
        $this->dx()->add($noAge, 'J18.9', true);

        $r = app(HmisReports::class)->opd(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));

        $this->assertSame(1, $r['diagnoses']['malaria_confirmed']['counts']['20y']['female']);
        $this->assertSame(1, $r['diagnoses']['malaria_confirmed']['counts']['29d_4y']['male']);
        $this->assertSame(2, $r['diagnoses']['malaria_confirmed']['total']);
        $this->assertSame(0, $r['diagnoses']['pneumonia']['total'], 'no age, not counted');
        $this->assertEqualsCanonicalizing([$uncoded->visit_no, $noAge->visit_no], array_column($r['missing'], 'visit_no'));
        $this->assertSame(1, $r['attendance']['new']['20y']['female']);
    }

    public function test_hmis_108_and_033b_and_the_notifiable_alert(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00'); // a Wednesday
        $v = $this->visit(['sex' => 'male', 'dob' => '1980-01-01'], '2026-10-13 08:00:00');
        $this->dx()->add($v, 'B05.9', true, 'confirmed', null, $this->doctor); // measles: notifiable
        $bed = Bed::factory()->create(['hospital_id' => $this->h->id]);
        Admission::query()->create(['uuid' => (string) Str::uuid(), 'hospital_id' => $this->h->id, 'patient_id' => $v->patient_id, 'visit_id' => $v->id, 'bed_id' => $bed->id,
            'status' => 'deceased', 'admitted_at' => '2026-10-13 09:00:00', 'discharged_at' => '2026-10-15 09:00:00']);

        $week = app(HmisReports::class)->weekly(now());
        $this->assertSame(['label' => 'Measles', 'cases' => 1, 'deaths' => 1], $week['conditions']['measles']);

        $ipd = app(HmisReports::class)->ipd(Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01'));
        $this->assertSame(1, $ipd['deaths']['20y']['male']);
        $this->assertSame(2, $ipd['bed_days']);

        $insight = (new \App\Support\Insights\Rules\NotifiableDiseases)->evaluate(new \App\Support\Insights\InsightContext($this->doctor, $this->h, \App\Support\Practice\Practice::of($this->h), now()));
        $this->assertNotNull($insight);
        $this->assertStringContainsString('Measles (1)', $insight->detail);
    }

    public function test_the_reports_page_and_its_exports(): void
    {
        $admin = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);

        $this->get(route('admin.reports.hmis'))->assertOk()->assertSee('HMIS 105');
        $this->get(route('admin.reports.hmis.export', ['form' => '105', 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
        $csv = $this->get(route('admin.reports.hmis.export', ['form' => '033b', 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Condition,Cases,Deaths', $csv);
    }
}
