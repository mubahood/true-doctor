<?php

namespace Tests\Feature\Api;

use App\Enums\DataRequestStatus;
use App\Models\DataRequest;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Data requests over the API (docs/api/admin.md): logged with the date the
 * answer is due, listed as a worklist, answered or refused once, and the
 * patient's data as the web's own PDF. DataRequestService holds the rules.
 */
class DataRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
    }

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role, 'name' => 'Owner '.$role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    private function patient(?Hospital $h = null, array $attrs = []): Patient
    {
        return Patient::factory()->create($attrs + ['hospital_id' => ($h ?? $this->h)->id, 'first_name' => 'Amina', 'last_name' => 'Nakato']);
    }

    /** @return array<string,mixed> */
    private function body(array $over = []): array
    {
        return $over + [
            'requester_name' => 'Amina Nakato',
            'requester_contact' => '0772 123456',
            'kind' => 'access',
            'received_on' => today()->toDateString(),
            'details' => 'Wants a copy of her records.',
        ];
    }

    private function logged(Hospital $h, array $attrs = []): DataRequest
    {
        app(CurrentHospital::class)->set($h->id);
        $r = new DataRequest($attrs + [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'requester_name' => 'Someone',
            'kind' => 'correction',
            'status' => DataRequestStatus::Open,
            'received_on' => today()->subDays(3)->toDateString(),
            'due_on' => today()->addDays(27)->toDateString(),
        ]);
        $r->forceFill(['hospital_id' => $h->id])->save();

        return $r;
    }

    public function test_a_request_is_logged_with_its_due_date_and_opened_again_by_its_uuid(): void
    {
        $this->as('hospital_admin');
        $patient = $this->patient();

        $res = $this->postJson('/api/v1/data-requests', $this->body(['patient_uuid' => $patient->uuid]))->assertCreated()
            ->assertJsonPath('message', 'Request logged. The answer is due within 30 days.');
        $data = $res->json('data');
        $this->assertSame('open', $data['status']);
        $this->assertSame('Open', $data['status_label']);
        $this->assertSame('warn', $data['status_tone']);
        $this->assertSame('See their data', $data['kind_label']);
        $this->assertSame(today()->addDays(30)->toDateString(), $data['due_on']);
        $this->assertSame(['uuid' => $patient->uuid, 'name' => 'Amina Nakato', 'patient_no' => $patient->patient_no], $data['patient']);
        $this->assertTrue($data['personal_data'], 'a request to see their data offers the PDF');
        $this->assertSame('Owner hospital_admin', $data['recorded_by']);
        $this->assertStringContainsString('PDF', $data['hint']);

        $this->getJson('/api/v1/data-requests/'.$data['uuid'])->assertOk()->assertJsonPath('data.details', 'Wants a copy of her records.');

        // Somebody not on the register: no patient, and no PDF to offer.
        $data = $this->postJson('/api/v1/data-requests', $this->body(['requester_name' => 'A walk-in', 'kind' => 'objection']))->assertCreated()->json('data');
        $this->assertNull($data['patient']);
        $this->assertFalse($data['personal_data']);
    }

    public function test_the_log_is_a_worklist_open_first_with_the_web_filters(): void
    {
        $this->as('hospital_admin');
        $patient = $this->patient(null, ['patient_no' => 'PT-7788']);
        $this->logged($this->h, ['requester_name' => 'Closed one', 'status' => DataRequestStatus::Done, 'outcome' => 'Sent', 'due_on' => today()->subDays(20)->toDateString()]);
        $late = $this->logged($this->h, ['requester_name' => 'Late one', 'due_on' => today()->subDay()->toDateString(), 'patient_id' => $patient->id]);
        $this->logged($this->h, ['requester_name' => 'Soon one', 'kind' => 'deletion', 'due_on' => today()->addDays(2)->toDateString()]);
        $this->logged($this->other, ['requester_name' => 'Elsewhere']);

        $res = $this->getJson('/api/v1/data-requests')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.response_days', 30);
        $this->assertSame(['Late one', 'Soon one', 'Closed one'], array_column($res->json('data'), 'requester_name'));
        $this->assertTrue($res->json('data.0.overdue'));
        $this->assertFalse($res->json('data.2.overdue'), 'a closed request is never overdue');

        $this->assertSame(['Closed one'], array_column($this->getJson('/api/v1/data-requests?status=done')->json('data'), 'requester_name'));
        $this->assertSame(['Soon one'], array_column($this->getJson('/api/v1/data-requests?kind=deletion')->json('data'), 'requester_name'));
        $this->assertSame([$late->uuid], array_column($this->getJson('/api/v1/data-requests?q=PT-7788')->json('data'), 'uuid'));
        $this->getJson('/api/v1/data-requests?status=pending')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_it_is_answered_or_refused_once_with_what_was_done(): void
    {
        $this->as('hospital_admin');
        $open = $this->logged($this->h);
        $other = $this->logged($this->h);

        $this->postJson("/api/v1/data-requests/{$open->uuid}/answer", [])->assertStatus(422)->assertJsonValidationErrors('outcome');
        $res = $this->postJson("/api/v1/data-requests/{$open->uuid}/answer", ['outcome' => 'Corrected the phone number.'])->assertOk()
            ->assertJsonPath('message', 'Marked as answered.');
        $this->assertSame('done', $res->json('data.status'));
        $this->assertSame('Corrected the phone number.', $res->json('data.outcome'));
        $this->assertSame('Owner hospital_admin', $res->json('data.closed_by'));

        $this->postJson("/api/v1/data-requests/{$open->uuid}/refuse", ['outcome' => 'Changed my mind'])->assertStatus(422)
            ->assertJsonPath('errors.outcome.0', 'This request was already closed.');
        $this->assertSame(DataRequestStatus::Done, $open->fresh()->status);

        $this->postJson("/api/v1/data-requests/{$other->uuid}/refuse", ['outcome' => 'Records are kept for the retention period.'])->assertOk()
            ->assertJsonPath('data.status', 'refused')->assertJsonPath('message', 'Marked as refused, with the reason.');
    }

    public function test_the_web_rules_refuse_a_bad_request(): void
    {
        $this->as('hospital_admin');
        $foreign = $this->patient($this->other);

        $this->postJson('/api/v1/data-requests', $this->body(['requester_name' => '']))->assertStatus(422)->assertJsonValidationErrors('requester_name');
        $this->postJson('/api/v1/data-requests', $this->body(['received_on' => today()->addDay()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('received_on');
        $this->postJson('/api/v1/data-requests', $this->body(['kind' => 'gossip']))->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson('/api/v1/data-requests', $this->body(['patient_uuid' => $foreign->uuid]))->assertStatus(422)
            ->assertJsonPath('errors.patient_uuid.0', 'That patient is not on this hospital\'s register.');
        $this->assertSame(0, DataRequest::withoutGlobalScopes()->count());
    }

    public function test_only_who_runs_the_settings_and_never_another_hospitals(): void
    {
        $this->getJson('/api/v1/data-requests')->assertUnauthorized();

        $foreign = $this->logged($this->other);
        $this->as('nurse');
        $this->getJson('/api/v1/data-requests')->assertForbidden();
        $this->postJson('/api/v1/data-requests', $this->body())->assertForbidden();

        $this->as('hospital_admin');
        $this->getJson("/api/v1/data-requests/{$foreign->uuid}")->assertNotFound();
        $this->postJson("/api/v1/data-requests/{$foreign->uuid}/answer", ['outcome' => 'Done'])->assertNotFound();
        $this->assertSame(DataRequestStatus::Open, DataRequest::withoutGlobalScopes()->find($foreign->id)->status);
    }

    public function test_a_request_sent_twice_with_one_key_is_logged_once(): void
    {
        $this->as('hospital_admin');

        $first = $this->postJson('/api/v1/data-requests', $this->body(), ['Idempotency-Key' => 'dr-1'])->assertCreated();
        $again = $this->postJson('/api/v1/data-requests', $this->body(), ['Idempotency-Key' => 'dr-1'])->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.uuid'), $again->json('data.uuid'));
        $this->assertSame(1, DataRequest::query()->count());
    }

    public function test_the_patients_data_is_the_webs_own_pdf_behind_the_same_checks(): void
    {
        $patient = $this->patient();
        $this->as('hospital_admin');

        $pdf = $this->get("/api/v1/patients/{$patient->uuid}/personal-data.pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertTrue(\Spatie\Activitylog\Models\Activity::query()->where('description', 'patient data exported')->where('subject_id', $patient->id)->exists());

        $this->getJson("/api/v1/patients/{$this->patient($this->other)->uuid}/personal-data.pdf")->assertNotFound();
        $this->getJson("/api/v1/patients/{$patient->uuid}/personal-data.json")->assertNotFound();

        $this->as('nurse');
        $this->getJson("/api/v1/patients/{$patient->uuid}/personal-data.pdf")->assertForbidden();
    }
}
