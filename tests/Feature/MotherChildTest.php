<?php

namespace Tests\Feature;

use App\Enums\PregnancyStatus;
use App\Livewire\Immunisations\Index as ImmunisationsDue;
use App\Livewire\Patients\Tabs\Growth;
use App\Livewire\Patients\Tabs\Immunisation as ImmunisationTab;
use App\Livewire\Patients\Tabs\Pregnancy as PregnancyTab;
use App\Models\AncContact;
use App\Models\GrowthMeasurement;
use App\Models\Hospital;
use App\Models\Immunisation;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\User;
use App\Models\Visit;
use App\Services\GrowthService;
use App\Services\ImmunisationService;
use App\Services\PregnancyService;
use App\Support\CurrentHospital;
use App\Support\MotherChild\WhoGrowth;
use App\Support\Navigation;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** The pregnancy record, the immunisation register and the growth chart (roadmap 04 §4, docs/mother-child.md). */
class MotherChildTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    private Patient $mother;

    private Patient $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        $this->hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->nurse = $this->staff('nurse');
        $this->mother = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subYears(26)->toDateString()]);
        $this->child = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'male', 'dob' => now()->subDays(140)->toDateString()]);
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $user = User::factory()->create(['hospital_id' => ($hospital ?? $this->hospital)->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function pregnancies(): PregnancyService
    {
        return app(PregnancyService::class);
    }

    private function immunisations(): ImmunisationService
    {
        return app(ImmunisationService::class);
    }

    private function weeksAgo(int $weeks): string
    {
        return now()->subWeeks($weeks)->toDateString();
    }

    // ── Pregnancy ────────────────────────────────────────────────────────

    public function test_the_edd_is_the_lmp_plus_280_days_and_gestation_and_the_contact_follow_it(): void
    {
        $p = $this->pregnancies()->open($this->mother, ['lmp' => $this->weeksAgo(20), 'gravida' => 2, 'para' => 1], $this->nurse);

        $this->assertSame(Carbon::parse($this->weeksAgo(20))->addDays(280)->toDateString(), $p->edd->toDateString());
        $this->assertTrue($p->edd_from_lmp);
        $this->assertSame(140, PregnancyService::gestationDays($p));
        $this->assertSame('20 w 0 d', PregnancyService::gestationLabel(140));
        $this->assertSame(1, PregnancyService::contactFor(12 * 7 + 6));
        $this->assertSame(2, PregnancyService::contactFor(13 * 7));
        $this->assertSame(8, PregnancyService::contactFor(43 * 7));

        // An EDD from a scan wins, and gestation is read from it.
        $later = $this->pregnancies()->update($p, ['edd' => now()->addDays(130)->toDateString()], $this->nurse);
        $this->assertFalse($later->edd_from_lmp);
        $this->assertSame(150, PregnancyService::gestationDays($later));
    }

    public function test_one_open_pregnancy_a_woman_only_and_para_below_gravida(): void
    {
        $uuid = (string) Str::uuid();
        $p = $this->pregnancies()->open($this->mother, ['uuid' => $uuid, 'lmp' => $this->weeksAgo(10), 'gravida' => 1], $this->nurse);
        $this->assertSame($p->id, $this->pregnancies()->open($this->mother, ['uuid' => $uuid, 'lmp' => $this->weeksAgo(10), 'gravida' => 1], $this->nurse)->id, 'a retry is the same pregnancy');

        foreach ([
            [$this->mother, ['lmp' => $this->weeksAgo(4), 'gravida' => 2], 'patient'],
            [$this->child, ['lmp' => $this->weeksAgo(4), 'gravida' => 1], 'patient'],
        ] as [$who, $data, $key]) {
            try {
                $this->pregnancies()->open($who, $data, $this->nurse);
                $this->fail('should refuse');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        $other = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subYears(30)->toDateString()]);
        try {
            $this->pregnancies()->open($other, ['lmp' => $this->weeksAgo(8), 'gravida' => 2, 'para' => 2], $this->nurse);
            $this->fail('para must be below gravida');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('para', $e->errors());
        }
        try {
            $this->pregnancies()->open($other, ['gravida' => 1], $this->nurse);
            $this->fail('needs a date');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('edd', $e->errors());
        }
        $this->assertSame(1, Pregnancy::count());
    }

    public function test_a_late_booking_shows_the_missed_contact_and_what_is_due_now(): void
    {
        $p = $this->pregnancies()->open($this->mother, ['lmp' => $this->weeksAgo(16), 'gravida' => 1], $this->nurse);
        $contact = $this->pregnancies()->recordContact($p, ['blood_pressure' => '118/76', 'weight' => 61, 'checks' => ['bp', 'weight', 'hiv']], null, $this->nurse);

        $this->assertSame(2, $contact->contact_no, 'counted against contact 2 (week 20)');
        $s = $this->pregnancies()->schedule($p->fresh());
        $this->assertSame('missed', $s['contacts'][0]['status']);
        $this->assertSame('done', $s['contacts'][1]['status']);
        $this->assertSame(3, $s['next']['no']);
        $due = array_column($s['due_checks'], 'key');
        $this->assertContains('syphilis', $due, 'a booking test not yet done is still due');
        $this->assertNotContains('hiv', $due);
        $this->assertSame([], $s['missed_checks']);

        // At 31 weeks with nothing more done, the dating scan's time has passed; the booking tests are still due.
        $s = $this->pregnancies()->schedule($p->fresh(), now()->addWeeks(15));
        $this->assertContains('ultrasound', array_column($s['missed_checks'], 'key'));
        $this->assertContains('syphilis', array_column($s['due_checks'], 'key'));
    }

    public function test_a_contact_is_validated_and_a_raised_pressure_and_risks_are_flagged(): void
    {
        $p = $this->pregnancies()->open($this->mother, ['lmp' => $this->weeksAgo(30), 'gravida' => 6, 'para' => 5, 'risk_factors' => ['hypertension']], $this->nurse);

        try {
            $this->pregnancies()->recordContact($p, ['blood_pressure' => 'high', 'weight' => 900, 'checks' => ['nonsense']], null, $this->nurse);
            $this->fail('should refuse');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['blood_pressure', 'weight', 'checks'], array_keys($e->errors()));
        }

        $uuid = (string) Str::uuid();
        $this->pregnancies()->recordContact($p, ['uuid' => $uuid, 'blood_pressure' => '150/95'], null, $this->nurse);
        $this->pregnancies()->recordContact($p, ['uuid' => $uuid, 'blood_pressure' => '150/95'], null, $this->nurse);
        $this->assertSame(1, AncContact::count(), 'a retried contact is recorded once');

        $flags = array_column($this->pregnancies()->schedule($p->fresh())['flags'], 'key');
        $this->assertContains('hypertension', $flags);
        $this->assertContains('grand_multipara', $flags);
        $this->assertContains('raised_bp', $flags);
    }

    public function test_closing_is_final(): void
    {
        $p = $this->pregnancies()->open($this->mother, ['lmp' => $this->weeksAgo(39), 'gravida' => 1], $this->nurse);
        $closed = $this->pregnancies()->close($p, PregnancyStatus::Delivered, Carbon::today(), 'Live boy', $this->nurse);

        $this->assertSame(PregnancyStatus::Delivered, $closed->status);
        $this->expectException(ValidationException::class);
        $this->pregnancies()->recordContact($closed, ['blood_pressure' => '120/80'], null, $this->nurse);
    }

    // ── Immunisation ─────────────────────────────────────────────────────

    public function test_the_card_reads_given_due_overdue_and_upcoming_from_the_birth_date(): void
    {
        $dob = $this->child->dob;
        $this->immunisations()->record($this->child, [
            ['dose_key' => 'bcg', 'given_on' => $dob->toDateString()],
            ['dose_key' => 'opv0', 'given_on' => $dob->toDateString()],
            ['dose_key' => 'opv1', 'given_on' => $dob->copy()->addDays(42)->toDateString()],
            ['dose_key' => 'penta1', 'given_on' => $dob->copy()->addDays(42)->toDateString()],
        ], null, $this->nurse);

        $card = collect($this->immunisations()->card($this->child))->keyBy('key');
        $this->assertSame('given', $card['bcg']['status']);
        $this->assertSame('overdue', $card['opv2']['status'], 'due at 10 weeks, two weeks of grace gone');
        $this->assertSame('overdue', $card['pcv1']['status']);
        $this->assertSame('upcoming', $card['mr1']['status']);
        $this->assertArrayNotHasKey('hpv', $card->all(), 'HPV is for girls');

        $girl = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subDays(75)->toDateString()]);
        $card = collect($this->immunisations()->card($girl))->keyBy('key');
        $this->assertSame('due', $card['opv2']['status'], 'due on day 70, within the grace');
        $this->assertSame('missed', $card['opv0']['status'], 'OPV 0 is not given after two weeks');
        $this->assertArrayHasKey('hpv', $card->all());

        $counts = ImmunisationService::counts($this->immunisations()->card($this->child));
        $this->assertSame(4, $counts['given']);
        $this->assertGreaterThan(0, $counts['overdue']);
    }

    public function test_a_dose_too_young_too_soon_or_twice_is_refused_and_a_void_lets_it_be_recorded_again(): void
    {
        $dob = $this->child->dob;
        $refuse = function (array $dose, string $field) {
            try {
                $this->immunisations()->record($this->child, [$dose], null, $this->nurse);
                $this->fail('should refuse');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("doses.0.{$field}", $e->errors());
            }
        };

        $refuse(['dose_key' => 'penta1', 'given_on' => $dob->copy()->addDays(20)->toDateString()], 'given_on');
        $refuse(['dose_key' => 'nope'], 'dose_key');
        $refuse(['dose_key' => 'bcg', 'given_on' => now()->addDay()->toDateString()], 'given_on');

        $this->immunisations()->record($this->child, [['dose_key' => 'opv1', 'given_on' => $dob->copy()->addDays(42)->toDateString()]], null, $this->nurse);
        $refuse(['dose_key' => 'opv2', 'given_on' => $dob->copy()->addDays(50)->toDateString()], 'given_on');
        $refuse(['dose_key' => 'opv1'], 'dose_key');

        $dose = Immunisation::query()->where('dose_key', 'opv1')->firstOrFail();
        $this->immunisations()->void($dose, 'Wrong child', $this->nurse);
        $this->immunisations()->record($this->child, [['dose_key' => 'opv1', 'given_on' => $dob->copy()->addDays(45)->toDateString()]], null, $this->nurse);
        $this->assertSame(2, Immunisation::query()->where('dose_key', 'opv1')->count(), 'the voided entry is kept');
        $this->assertSame(1, Immunisation::query()->standing()->where('dose_key', 'opv1')->count());
    }

    public function test_the_due_list_has_the_children_with_doses_due_overdue_first(): void
    {
        $upToDate = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subDays(3)->toDateString()]);
        $this->immunisations()->record($upToDate, [['dose_key' => 'bcg', 'given_on' => now()->subDays(3)->toDateString()], ['dose_key' => 'opv0', 'given_on' => now()->subDays(3)->toDateString()]], null, $this->nurse);
        $adult = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'dob' => now()->subYears(40)->toDateString()]);

        $list = $this->immunisations()->dueList();
        $ids = $list->map(fn ($r) => $r['patient']->id)->all();
        $this->assertContains($this->child->id, $ids);
        $this->assertNotContains($upToDate->id, $ids);
        $this->assertNotContains($adult->id, $ids);
        $this->assertGreaterThan(0, $list->first()['overdue']);

        Livewire::withoutLazyLoading()->actingAs($this->nurse)->test(ImmunisationsDue::class)->assertOk()->assertSee($this->child->full_name)
            ->set('status', 'due')->assertDontSee($this->child->full_name);
    }

    // ── Growth ───────────────────────────────────────────────────────────

    public function test_who_z_scores_match_the_standard(): void
    {
        $this->assertEqualsWithDelta(0.0, WhoGrowth::weightForAge(3.3464, 0, true), 0.001, 'boy at birth, the median');
        $this->assertEqualsWithDelta(-2.0, WhoGrowth::weightForAge((float) WhoGrowth::weightAt(-2, 365, false), 365, false), 0.001);
        $this->assertEqualsWithDelta(0.0, WhoGrowth::heightForAge(75.7391, 365, true), 0.01);
        $this->assertLessThan(-3, WhoGrowth::weightForAge(5.0, 365, true));
        $this->assertNull(WhoGrowth::weightForAge(10, 2000, true), 'past five years');
        $this->assertSame('underweight', GrowthService::weightBand(-2.5)['key']);
        $this->assertSame('severely_stunted', GrowthService::heightBand(-3.2)['key']);
        $this->assertSame('severe', GrowthService::muacBand(11.0, 400)['key']);
        $this->assertNull(GrowthService::muacBand(11.0, 100), 'MUAC from six months');
    }

    public function test_points_merge_visit_vitals_with_clinic_measurements_and_flag_faltering(): void
    {
        $dob = $this->child->dob;
        Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $this->child->id, 'weight' => '5.40', 'height' => '60', 'vitals_recorded_at' => $dob->copy()->addDays(60)->setTime(9, 0)]);
        $service = app(GrowthService::class);
        $service->record($this->child, ['measured_on' => $dob->copy()->addDays(95)->toDateString(), 'weight' => '6.10', 'height' => '62.5'], null, $this->nurse);
        $service->record($this->child, ['measured_on' => $dob->copy()->addDays(130)->toDateString(), 'weight' => '6.05'], null, $this->nurse);

        $points = $service->points($this->child);
        $this->assertCount(3, $points);
        $this->assertSame('visit', $points[0]['source']);
        $this->assertNotNull($points[0]['weight_z']);
        $a = $service->assessment($this->child, $points);
        $this->assertTrue($a['faltering']['flag'], 'no gain over a month');
        $this->assertSame('62.5', $a['height']['value']);

        $this->expectException(ValidationException::class);
        $service->record($this->child, ['measured_on' => now()->toDateString()], null, $this->nurse);
    }

    // ── Where the tabs show, and who may write ──────────────────────────

    public function test_the_tabs_show_only_where_they_apply(): void
    {
        $doctor = $this->staff('doctor');
        $man = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'male', 'dob' => now()->subYears(40)->toDateString()]);
        $grandma = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subYears(70)->toDateString()]);

        $this->actingAs($doctor)->get(route('admin.patients.show', $this->mother))->assertOk()->assertSee('ptab-pregnancy', false)->assertDontSee('ptab-immunisation', false);
        $this->actingAs($doctor)->get(route('admin.patients.show', $this->child))->assertSee('ptab-immunisation', false)->assertSee('ptab-growth', false)->assertDontSee('ptab-pregnancy', false);
        $this->actingAs($doctor)->get(route('admin.patients.show', $man))->assertDontSee('ptab-pregnancy', false)->assertDontSee('ptab-growth', false);
        $this->actingAs($doctor)->get(route('admin.patients.show', $grandma))->assertDontSee('ptab-pregnancy', false);

        // A record already there always shows; a role that cannot read the clinical history never sees it.
        $this->pregnancies()->open($this->mother, ['lmp' => $this->weeksAgo(10), 'gravida' => 1], $this->nurse);
        $this->assertTrue(PregnancyService::onPatient($this->mother, $this->hospital, $doctor));
        $this->assertFalse(PregnancyService::onPatient($this->mother, $this->hospital, $this->staff('accountant')));

        // A dental clinic sees none of them, nor the due list in its menu.
        $dental = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        app(CurrentHospital::class)->set($dental->id);
        $dentist = $this->staff('doctor', $dental);
        $kid = Patient::factory()->create(['hospital_id' => $dental->id, 'sex' => 'female', 'dob' => now()->subYears(2)->toDateString()]);
        $this->actingAs($dentist)->get(route('admin.patients.show', $kid))->assertDontSee('ptab-immunisation', false)->assertDontSee('ptab-growth', false);
        $routes = collect(Navigation::for($dentist, false)['sections'])->flatMap(fn ($s) => $s['entries'] ?? [])->pluck('route')->filter()->all();
        $this->assertNotContains('admin.immunisations.index', $routes);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    public function test_the_tabs_record_through_the_services(): void
    {
        Livewire::withoutLazyLoading()->actingAs($this->nurse)->test(PregnancyTab::class, ['patientId' => $this->mother->id])
            ->assertSee('No current pregnancy')
            ->call('openForm')
            ->set('lmp', $this->weeksAgo(22))
            ->assertSet('edd', Carbon::parse($this->weeksAgo(22))->addDays(280)->toDateString())
            ->set('gravida', 2)->set('para', 1)
            ->call('save')->assertHasNoErrors()
            ->assertSee('Due at this contact')
            ->call('openContact')
            ->set('bloodPressure', '120/80')->set('checks', ['bp', 'fundal_height'])
            ->call('saveContact')->assertHasNoErrors()
            ->call('openContact')->set('bloodPressure', 'x')->call('saveContact')->assertHasErrors('bloodPressure');
        $this->assertSame(1, AncContact::count());

        Livewire::withoutLazyLoading()->actingAs($this->nurse)->test(ImmunisationTab::class, ['patientId' => $this->child->id])
            ->assertSee('Immunisation card')
            ->call('openGive', 'bcg')->set('givenOn', $this->child->dob->toDateString())->call('give')->assertHasNoErrors()
            ->call('openGive', 'penta1')->set('givenOn', $this->child->dob->toDateString())->call('give')->assertHasErrors('givenOn');
        $this->assertSame(1, Immunisation::count());

        Livewire::withoutLazyLoading()->actingAs($this->nurse)->test(Growth::class, ['patientId' => $this->child->id])
            ->call('openForm')->set('weight', '6.2')->set('height', '63')->call('save')->assertHasNoErrors()
            ->assertSee('Weight-for-age')->assertSeeHtml('mc-dot');
        $this->assertSame(1, GrowthMeasurement::count());
    }

    public function test_a_receptionist_reads_but_does_not_record(): void
    {
        $desk = $this->staff('receptionist');

        Livewire::withoutLazyLoading()->actingAs($desk)->test(ImmunisationTab::class, ['patientId' => $this->child->id])->assertOk()->call('openGive', 'bcg')->assertForbidden();
        Sanctum::actingAs($desk);
        $this->getJson("/api/v1/patients/{$this->child->uuid}/immunisations")->assertOk();
        $this->postJson("/api/v1/patients/{$this->child->uuid}/immunisations", ['doses' => [['dose_key' => 'bcg']]])->assertForbidden()->assertJsonPath('success', false);
        $this->postJson("/api/v1/patients/{$this->mother->uuid}/pregnancies", ['lmp' => $this->weeksAgo(8), 'gravida' => 1])->assertForbidden();

        Sanctum::actingAs($this->staff('accountant'));
        $this->getJson("/api/v1/patients/{$this->child->uuid}/growth")->assertForbidden();
    }

    // ── The API ──────────────────────────────────────────────────────────

    public function test_the_api_records_and_reads_the_three_records(): void
    {
        $this->getJson("/api/v1/patients/{$this->mother->uuid}/pregnancy")->assertUnauthorized();
        Sanctum::actingAs($this->nurse);

        $preg = $this->postJson("/api/v1/patients/{$this->mother->uuid}/pregnancies", ['lmp' => $this->weeksAgo(13), 'gravida' => 1, 'risk_factors' => ['anaemia']])
            ->assertCreated()->assertJsonPath('data.schedule.gestation_label', '13 w 0 d')->json('data.uuid');
        $this->postJson("/api/v1/pregnancies/{$preg}/contacts", ['blood_pressure' => '110/70', 'checks' => ['bp', 'hb']])
            ->assertCreated()->assertJsonPath('data.contact.contact_no', 2);
        $this->patchJson("/api/v1/pregnancies/{$preg}", ['birth_place' => 'Mengo'])->assertOk()->assertJsonPath('data.birth_plan.place', 'Mengo')->assertJsonPath('data.gravida', 1);
        $this->postJson("/api/v1/patients/{$this->mother->uuid}/pregnancies", ['gravida' => 0])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->getJson("/api/v1/patients/{$this->mother->uuid}/pregnancy")->assertOk()
            ->assertJsonPath('data.current.uuid', $preg)->assertJsonCount(1, 'data.current.contacts');

        $this->postJson("/api/v1/patients/{$this->child->uuid}/immunisations", ['doses' => [['dose_key' => 'bcg', 'given_on' => $this->child->dob->toDateString()]]])
            ->assertCreated()->assertJsonPath('data.counts.given', 1);
        $this->getJson("/api/v1/patients/{$this->child->uuid}/immunisations")->assertOk()->assertJsonPath('data.card.0.status', 'given');
        $this->getJson('/api/v1/immunisations/due')->assertOk()->assertJsonPath('data.0.patient_uuid', $this->child->uuid)->assertJsonPath('meta.total', 1);
        $bcg = Immunisation::query()->firstOrFail();
        $this->postJson("/api/v1/immunisations/{$bcg->uuid}/void", ['reason' => 'Entered twice'])->assertOk();

        $this->postJson("/api/v1/patients/{$this->child->uuid}/growth", ['weight' => 6.3, 'height' => 62, 'muac' => 13.5])->assertCreated();
        $this->getJson("/api/v1/patients/{$this->child->uuid}/growth")->assertOk()->assertJsonPath('data.assessment.weight.value', '6.30')->assertJsonCount(1, 'data.points');

        $this->getJson("/api/v1/patients/{$this->child->uuid}/summary")->assertOk()
            ->assertJsonPath('data.growth.weight.value', '6.30')->assertJsonPath('data.immunisation.given', 0)
            ->assertJsonMissingPath('data.pregnancy');
        $summary = $this->getJson("/api/v1/patients/{$this->mother->uuid}/summary")->assertOk();
        $this->assertContains('pregnancy', $summary->json('data.sections'));
        $this->assertSame('Anaemia', $summary->json('data.pregnancy.flags.0'));
    }

    public function test_a_replayed_write_records_once(): void
    {
        Sanctum::actingAs($this->nurse);
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['weight' => 6.3];

        $first = $this->postJson("/api/v1/patients/{$this->child->uuid}/growth", $body, $key)->assertCreated()->json('data.uuid');
        $again = $this->postJson("/api/v1/patients/{$this->child->uuid}/growth", $body, $key)->assertCreated()->json('data.uuid');

        $this->assertSame($first, $again);
        $this->assertSame(1, GrowthMeasurement::count());
    }

    public function test_another_hospital_never_sees_these_records(): void
    {
        $p = $this->pregnancies()->open($this->mother, ['lmp' => $this->weeksAgo(10), 'gravida' => 1], $this->nurse);
        $this->immunisations()->record($this->child, [['dose_key' => 'bcg', 'given_on' => $this->child->dob->toDateString()]], null, $this->nurse);
        app(GrowthService::class)->record($this->child, ['weight' => 6], null, $this->nurse);

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $this->assertSame(0, Pregnancy::count() + Immunisation::count() + GrowthMeasurement::count() + AncContact::count());

        $stranger = $this->staff('doctor', $other);
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/pregnancies/{$p->uuid}/contacts", ['blood_pressure' => '120/80'])->assertNotFound();
        $this->getJson("/api/v1/patients/{$this->child->uuid}/immunisations")->assertNotFound();
        $this->getJson('/api/v1/immunisations/due')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
