<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Services\DiagnosisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/** Diagnoses to code for the app: GET diagnoses/worklist, POST diagnoses/worklist/{visit} (docs/api/diagnoses.md). */
class DiagnosisWorklistApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $h;

    private User $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->h = $this->hospital();
        $this->withHospital($this->h);
        $this->records = $this->staff('records_officer');
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function visit(?string $diagnosis, array $patient = [], ?Hospital $h = null, ?User $doctor = null): Visit
    {
        $h ??= $this->h;
        $p = Patient::factory()->create($patient + ['hospital_id' => $h->id]);

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'diagnosis' => $diagnosis, 'doctor_user_id' => $doctor?->id]);
    }

    public function test_the_worklist_lists_written_diagnoses_with_no_code_newest_first_with_codes_that_may_fit(): void
    {
        $doctor = $this->staff('doctor');
        $older = $this->visit('Hypertension, adequately controlled', ['first_name' => 'Ruth', 'last_name' => 'Kobusingye'], doctor: $doctor);
        $newer = $this->visit('Uncomplicated malaria, confirmed by RDT', ['first_name' => 'Amina', 'last_name' => 'Nakato']);
        $coded = $this->visit('Asthma');
        app(DiagnosisService::class)->add($coded, 'J45.9', true, 'confirmed', null, $doctor);
        $this->visit(null);
        $this->visit('');
        $foreign = $this->hospital();
        $this->visit('Malaria', [], $foreign);
        $this->withHospital($this->h);

        Sanctum::actingAs($this->records);
        $res = $this->getJson('/api/v1/diagnoses/worklist')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.uuid', $newer->uuid)
            ->assertJsonPath('data.0.visit_no', $newer->visit_no)
            ->assertJsonPath('data.0.patient.name', 'Amina Nakato')
            ->assertJsonPath('data.0.diagnosis', 'Uncomplicated malaria, confirmed by RDT')
            ->assertJsonPath('data.0.suggestions.0.code', 'B50.9')
            ->assertJsonPath('data.1.uuid', $older->uuid)
            ->assertJsonPath('data.1.doctor', $doctor->name)
            ->assertJsonPath('data.1.suggestions.0.code', 'I10');
        $this->assertSame(['uuid', 'visit_no', 'created_at', 'patient', 'doctor', 'diagnosis', 'suggestions'], array_keys($res->json('data.0')));
        $this->assertSame(['code', 'title', 'system'], array_keys($res->json('data.0.suggestions.0')));

        // Narrowed to a patient, every word somewhere, or to a visit number.
        $this->getJson('/api/v1/diagnoses/worklist?q=ruth%20kobusingye')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $older->uuid);
        $this->getJson('/api/v1/diagnoses/worklist?q='.urlencode($newer->visit_no))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $newer->uuid);
    }

    public function test_the_records_officer_codes_from_the_list_and_the_visit_leaves_it(): void
    {
        $v = $this->visit('Uncomplicated malaria, confirmed by RDT');
        Sanctum::actingAs($this->records);

        // The catalogue search is theirs too; the visit page's coding stays the clinician's.
        $this->getJson('/api/v1/diagnoses/search?q=malaria')->assertOk()->assertJsonPath('data.0.code', fn ($c) => str_starts_with((string) $c, 'B5'));
        $this->postJson("/api/v1/visits/{$v->uuid}/diagnoses", ['code' => 'B50.9'])->assertForbidden();

        $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", ['code' => 'b50.9'])
            ->assertCreated()
            ->assertJsonPath('message', 'Coded.')
            ->assertJsonPath('data.code', 'B50.9')
            ->assertJsonPath('data.primary', true)
            ->assertJsonPath('data.certainty', 'confirmed');
        $this->getJson('/api/v1/diagnoses/worklist')->assertOk()->assertJsonPath('meta.total', 0);

        $current = app(DiagnosisService::class)->current($v);
        $this->assertSame(['B50.9'], $current->pluck('code')->all());
        $this->assertSame($this->records->id, $current->first()->recorded_by);
    }

    public function test_suspected_is_kept_and_a_visit_coded_meanwhile_is_refused_not_given_a_second_primary(): void
    {
        $doctor = $this->staff('doctor');
        $a = $this->visit('Hypertension');
        $b = $this->visit('Suspected malaria');

        Sanctum::actingAs($this->records);
        $this->postJson("/api/v1/diagnoses/worklist/{$b->uuid}", ['code' => 'B54', 'certainty' => 'suspected'])
            ->assertCreated()->assertJsonPath('data.certainty', 'suspected');

        // A doctor codes A on the visit while the officer's list still shows it.
        Sanctum::actingAs($doctor);
        $this->postJson("/api/v1/visits/{$a->uuid}/diagnoses", ['code' => 'I10'])->assertCreated();

        Sanctum::actingAs($this->records);
        $this->postJson("/api/v1/diagnoses/worklist/{$a->uuid}", ['code' => 'I15.9'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.code.0', fn ($m) => str_starts_with((string) $m, 'This visit was coded meanwhile: I10'));
        $this->assertSame(['I10'], app(DiagnosisService::class)->current($a)->pluck('code')->all());

        // The web's one press refuses it the same way.
        Livewire::actingAs($this->records)->test(\App\Livewire\Diagnoses\Worklist::class)
            ->call('code', $a->id, 'I15.9')
            ->assertDispatched('toast', type: 'error');
        $this->assertSame(1, VisitDiagnosis::query()->where('visit_id', $a->id)->count());
    }

    public function test_only_whoever_codes_may_read_or_code_the_worklist(): void
    {
        $v = $this->visit('Malaria');

        $this->getJson('/api/v1/diagnoses/worklist')->assertUnauthorized();

        foreach (['nurse', 'receptionist', 'lab_technician'] as $role) {
            Sanctum::actingAs($this->staff($role));
            $this->getJson('/api/v1/diagnoses/worklist')->assertForbidden()->assertJsonPath('code', 'forbidden');
            $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", ['code' => 'B54'])->assertForbidden();
        }
        // Neither visits nor coding: no catalogue search either.
        Sanctum::actingAs($this->staff('accountant'));
        $this->getJson('/api/v1/diagnoses/search?q=malaria')->assertForbidden();

        Sanctum::actingAs($this->staff('doctor'));
        $this->getJson('/api/v1/diagnoses/worklist')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(0, VisitDiagnosis::query()->count());
    }

    public function test_another_hospitals_visit_is_not_found(): void
    {
        $other = $this->hospital();
        $foreign = $this->visit('Malaria', [], $other);
        $this->withHospital($this->h);

        Sanctum::actingAs($this->records);
        $this->postJson("/api/v1/diagnoses/worklist/{$foreign->uuid}", ['code' => 'B54'])->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertSame(0, VisitDiagnosis::query()->withoutGlobalScopes()->count());
    }

    public function test_what_is_sent_is_checked(): void
    {
        $v = $this->visit('Malaria');
        $plain = $this->visit(null);
        Sanctum::actingAs($this->records);

        $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", [])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", ['code' => 'ZZZ.9'])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'That is not a code in the diagnosis list.');
        $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", ['code' => 'B54', 'certainty' => 'likely'])->assertStatus(422)->assertJsonValidationErrors(['certainty']);
        $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", ['code' => 'B54', 'uuid' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors(['uuid']);
        $this->postJson("/api/v1/diagnoses/worklist/{$plain->uuid}", ['code' => 'B54'])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'This visit has no written diagnosis to code from the worklist. Code it on the visit.');
        $this->getJson('/api/v1/diagnoses/worklist?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->assertSame(0, VisitDiagnosis::query()->count());
    }

    public function test_a_press_sent_again_codes_once(): void
    {
        $v = $this->visit('Malaria');
        Sanctum::actingAs($this->records);
        $body = ['code' => 'B54', 'uuid' => (string) Str::uuid()];

        $first = $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", $body, ['Idempotency-Key' => 'dx-1'])->assertCreated();
        $again = $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", $body, ['Idempotency-Key' => 'dx-1'])->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data'), $again->json('data'));

        // The same press under a new key (the sheet reopened): the device's uuid finds the row.
        $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", $body, ['Idempotency-Key' => 'dx-2'])->assertCreated()->assertJsonPath('data.uuid', $body['uuid']);
        $this->assertSame(1, VisitDiagnosis::query()->count());

        // The same entry with another code is not a repeat: it is refused with what is coded,
        // worded as one's own earlier press, not as somebody else's.
        $this->postJson("/api/v1/diagnoses/worklist/{$v->uuid}", ['code' => 'B50.9', 'uuid' => $body['uuid']], ['Idempotency-Key' => 'dx-4'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', fn ($m) => str_starts_with((string) $m, 'You have already coded this visit: B54'));
        $this->assertSame(['B54'], app(DiagnosisService::class)->current($v)->pluck('code')->all());

        // Another visit cannot borrow that uuid.
        $w = $this->visit('Malaria');
        $this->postJson("/api/v1/diagnoses/worklist/{$w->uuid}", $body, ['Idempotency-Key' => 'dx-3'])->assertStatus(422)->assertJsonValidationErrors(['uuid']);
        $this->assertSame(1, VisitDiagnosis::query()->count());
    }

    public function test_a_page_is_at_most_fifty_rows(): void
    {
        $this->visit('Malaria');
        Sanctum::actingAs($this->records);

        $this->getJson('/api/v1/diagnoses/worklist?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->getJson('/api/v1/diagnoses/worklist?per_page=5')->assertOk()->assertJsonPath('meta.per_page', 5)->assertJsonPath('meta.last_page', 1);
    }
}
