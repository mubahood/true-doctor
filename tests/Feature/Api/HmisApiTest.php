<?php

namespace Tests\Feature\Api;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\DiagnosisService;
use App\Services\Hmis\HmisReports;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Ministry reports for the app (docs/api/reports.md, HMIS): the forms,
 * one form's figures for a month or a week, the missing data, the
 * notifiable alert and the web's own PDF and CSV, behind reports.view and
 * about one hospital only.
 */
class HmisApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->h->id);
        $this->admin = $this->staff('hospital_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function visit(array $patient = [], ?string $at = null, ?Hospital $h = null): Visit
    {
        $h ??= $this->h;
        $p = Patient::factory()->create($patient + ['hospital_id' => $h->id, 'sex' => 'female', 'dob' => '1990-01-01']);

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'created_at' => $at ?? now()]);
    }

    private function dx(): DiagnosisService
    {
        return app(DiagnosisService::class);
    }

    /** @param list<array<string,mixed>> $lines */
    private function line(array $lines, string $label): array
    {
        foreach ($lines as $l) {
            if ($l['label'] === $label) {
                return $l;
            }
        }
        $this->fail("No line {$label}");
    }

    public function test_the_forms_where_each_opens_and_the_notifiable_alert(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $v = $this->visit(['sex' => 'male', 'dob' => '1980-01-01'], '2026-10-13 08:00:00');
        $this->dx()->add($v, 'B05.9', true); // measles: notifiable
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/reports/hmis')->assertOk()
            ->assertJsonPath('data.forms.0', ['key' => '105', 'label' => 'HMIS 105 · outpatients (monthly)', 'period' => 'month'])
            ->assertJsonPath('data.forms.1.key', '108')
            ->assertJsonPath('data.forms.2', ['key' => '033b', 'label' => 'HMIS 033b · surveillance (weekly)', 'period' => 'week'])
            ->assertJsonPath('data.default_month', '2026-09')
            ->assertJsonPath('data.default_week', '2026-10-05')
            ->assertJsonPath('data.version', config('hmis.version'))
            ->assertJsonPath('data.notifiable.days', 7)
            ->assertJsonPath('data.notifiable.total', 1)
            ->assertJsonPath('data.notifiable.cases.0', ['label' => 'Measles', 'count' => 1]);
    }

    public function test_a_month_of_outpatients_counts_by_band_and_sex_and_lists_what_it_cannot_count(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $adult = $this->visit(['sex' => 'female', 'dob' => '1990-05-01'], '2026-09-10 09:00:00');
        $child = $this->visit(['sex' => 'male', 'dob' => '2023-01-01'], '2026-09-11 09:00:00');
        $uncoded = $this->visit(['sex' => 'male', 'dob' => '2000-01-01'], '2026-09-12 09:00:00');
        $noAge = $this->visit(['sex' => 'male', 'dob' => null], '2026-09-13 09:00:00');
        $this->dx()->add($adult, 'B50.9', true);
        $this->dx()->add($child, 'B50.9', true);
        $this->dx()->add($noAge, 'J18.9', true);
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/v1/reports/hmis/105?month=2026-09')->assertOk()
            ->assertJsonPath('data.form', '105')
            ->assertJsonPath('data.period', ['kind' => 'month', 'month' => '2026-09', 'week' => null, 'from' => '2026-09-01', 'to' => '2026-09-30', 'label' => 'September 2026'])
            ->assertJsonPath('data.bands.0', ['key' => '0_28d', 'label' => '0-28 days'])
            ->assertJsonCount(count(config('hmis.age_bands')), 'data.bands')
            ->assertJsonPath('data.missing_total', 2)
            ->assertJsonPath('data.conditions', [])
            ->assertJsonPath('data.notes.0', ['label' => 'All other diagnoses', 'value' => 0])
            ->assertJsonPath('data.notes.1', ['label' => 'Visits this month', 'value' => 4]);

        $lines = $res->json('data.lines');
        $malaria = $this->line($lines, 'Malaria, confirmed');
        $this->assertSame(2, $malaria['total']);
        $this->assertSame(['male' => 0, 'female' => 1], $malaria['counts']['20y']);
        $this->assertSame(['male' => 1, 'female' => 0], $malaria['counts']['29d_4y']);
        $this->assertSame(0, $this->line($lines, 'Pneumonia')['total'], 'no age, not counted');
        $this->assertSame(['label' => 'New attendance', 'total' => 3], array_intersect_key($lines[0], ['label' => 1, 'total' => 1]));
        $this->assertSame('Re-attendance', $lines[1]['label']);

        $missing = $res->json('data.missing');
        $this->assertEqualsCanonicalizing([$uncoded->visit_no, $noAge->visit_no], array_column($missing, 'visit_no'));
        $noAgeRow = collect($missing)->firstWhere('visit_no', $noAge->visit_no);
        $this->assertSame(['no age'], $noAgeRow['why']);
        $this->assertSame($noAge->uuid, $noAgeRow['visit_uuid']);
        $this->assertNotNull($noAgeRow['patient_uuid']);

        // The same lines the web page draws, from the same service call.
        $web = app(HmisReports::class)->forForm('105', '2026-09', '');
        $this->assertSame(array_keys(app(HmisReports::class)->lines('105', $web)), array_column($lines, 'label'));

        $this->assertStringEndsWith('/api/v1/reports/hmis/105.pdf?month=2026-09', $res->json('data.files.pdf'));
        $this->assertStringEndsWith('/api/v1/reports/hmis/105.csv?month=2026-09', $res->json('data.files.csv'));
    }

    public function test_inpatients_for_a_month_and_the_surveillance_week(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00'); // a Wednesday
        $v = $this->visit(['sex' => 'male', 'dob' => '1980-01-01'], '2026-10-13 08:00:00');
        $this->dx()->add($v, 'B05.9', true);
        $bed = Bed::factory()->create(['hospital_id' => $this->h->id]);
        Admission::query()->create(['uuid' => (string) Str::uuid(), 'hospital_id' => $this->h->id, 'patient_id' => $v->patient_id, 'visit_id' => $v->id, 'bed_id' => $bed->id,
            'status' => 'deceased', 'admitted_at' => '2026-10-13 09:00:00', 'discharged_at' => '2026-10-15 09:00:00']);
        Sanctum::actingAs($this->admin);

        $ipd = $this->getJson('/api/v1/reports/hmis/108?month=2026-10')->assertOk()
            ->assertJsonPath('data.form', '108')
            ->assertJsonPath('data.lines.2.label', 'Deaths')
            ->assertJsonPath('data.lines.2.total', 1)
            ->assertJsonPath('data.lines.2.counts.20y.male', 1)
            ->assertJsonPath('data.lines.0.label', 'Admissions')
            ->assertJsonPath('data.notes.0', ['label' => 'Bed days', 'value' => 2])
            ->assertJsonPath('data.notes.1', ['label' => 'Absconded', 'value' => 0])
            ->assertJsonPath('data.notes.2', ['label' => 'Discharge diagnosis: Measles', 'value' => 1]);
        $this->assertSame(0, $ipd->json('data.missing_total'));

        $week = $this->getJson('/api/v1/reports/hmis/033b?week=2026-10-15')->assertOk()
            ->assertJsonPath('data.period.kind', 'week')
            ->assertJsonPath('data.period.from', '2026-10-12')
            ->assertJsonPath('data.period.to', '2026-10-18')
            ->assertJsonPath('data.period.number', 42)
            ->assertJsonPath('data.lines', [])
            ->assertJsonPath('data.missing', []);
        $measles = collect($week->json('data.conditions'))->firstWhere('key', 'measles');
        $this->assertSame(['key' => 'measles', 'label' => 'Measles', 'cases' => 1, 'deaths' => 1], $measles);
        $this->assertStringEndsWith('/api/v1/reports/hmis/033b.pdf?week=2026-10-12', $week->json('data.files.pdf'));
    }

    public function test_the_files_are_the_webs_behind_the_same_check(): void
    {
        Sanctum::actingAs($this->admin);
        $this->get('/api/v1/reports/hmis/105.pdf?month=2026-09')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/v1/reports/hmis/108.pdf?month=2026-09')->assertOk()->assertHeader('content-type', 'application/pdf');
        $csv = $this->get('/api/v1/reports/hmis/033b.csv?week=2026-10-12')->assertOk()->streamedContent();
        $this->assertStringContainsString('Condition,Cases,Deaths', $csv);
        $csv = $this->get('/api/v1/reports/hmis/105.csv?month=2026-09')->assertOk()->streamedContent();
        $this->assertStringContainsString('New attendance', $csv);

        Sanctum::actingAs($this->staff('nurse'));
        $this->get('/api/v1/reports/hmis/105.pdf?month=2026-09')->assertForbidden();
        $this->get('/api/v1/reports/hmis/033b.csv')->assertForbidden();
    }

    public function test_only_people_who_read_reports_and_only_good_dates(): void
    {
        $this->getJson('/api/v1/reports/hmis')->assertUnauthorized();

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/reports/hmis')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/v1/reports/hmis/105')->assertForbidden()->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/reports/hmis/105?month=2026-13')->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['month']);
        $this->getJson('/api/v1/reports/hmis/033b?week=14-10-2026')->assertStatus(422)->assertJsonValidationErrors(['week']);
        $this->getJson('/api/v1/reports/hmis/999')->assertNotFound();
    }

    public function test_one_hospital_never_counts_anothers_patients(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = $this->visit(['sex' => 'female', 'dob' => '1990-05-01'], '2026-09-10 09:00:00', $other);
        $this->dx()->add($theirs, 'B50.9', true);
        $this->visit(['sex' => 'male', 'dob' => null], '2026-09-11 09:00:00', $other);

        app(CurrentHospital::class)->set($this->h->id);
        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/reports/hmis/105?month=2026-09')->assertOk()
            ->assertJsonPath('data.missing_total', 0)
            ->assertJsonPath('data.notes.1.value', 0);
        $this->assertSame(0, $this->line($res->json('data.lines'), 'Malaria, confirmed')['total']);

        // A super admin over the API has no hospital: no form adds every hospital up.
        app(CurrentHospital::class)->set(null);
        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $super->syncSpatieRole();
        Sanctum::actingAs($super);
        $this->getJson('/api/v1/reports/hmis/105?month=2026-09')->assertNotFound();
        $this->get('/api/v1/reports/hmis/105.csv?month=2026-09')->assertNotFound();
    }

    public function test_a_month_is_read_from_its_first_day_even_on_the_31st(): void
    {
        // "2026-09" read as Y-m alone on 31 October would be 1 October.
        Carbon::setTestNow('2026-10-31 10:00:00');
        $september = app(HmisReports::class)->forForm('105', '2026-09', '');
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $september['period']);

        $this->assertSame('2026-09', HmisReports::defaultMonth());
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], app(HmisReports::class)->forForm('108', 'not a month', '')['period']);
        $this->assertSame('2026-10-19', app(HmisReports::class)->forForm('033b', '', 'nonsense')['week']['from'], 'a bad day is last week');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/reports/hmis/105?month=2026-09')->assertOk()->assertJsonPath('data.period.from', '2026-09-01');
    }
}
