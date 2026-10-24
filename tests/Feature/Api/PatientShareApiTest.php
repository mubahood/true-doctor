<?php

namespace Tests\Feature\Api;

use App\Enums\SharedDocumentType;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\LabService;
use App\Services\Messaging\ConsentService;
use App\Services\Messaging\MessagingSettings;
use App\Services\PatientLinks\PatientLinkService;
use App\Services\PrescriptionService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Share a link" from the phone's patient record (docs/api/patient-links.md):
 * what can be sent now, the link's QR, the search and the withdraw rights on
 * the list, and the text message on the open log.
 */
class PatientShareApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['name' => 'Kato Medical Centre']);
        app(CurrentHospital::class)->set($this->h->id);
        $this->doctor = $this->user('doctor');
        $this->patient = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'patient_no' => 'PT-2026-000777A']);
    }

    private function user(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function labOrder(bool $completed = true, ?Patient $patient = null): LabOrder
    {
        $patient ??= $this->patient;
        $visit = Visit::factory()->create(['hospital_id' => $patient->hospital_id, 'patient_id' => $patient->id]);
        $test = LabTest::query()->where('hospital_id', $patient->hospital_id)->where('name', 'Malaria RDT')->first()
            ?? LabTest::factory()->create(['hospital_id' => $patient->hospital_id, 'name' => 'Malaria RDT']);
        $order = app(LabService::class)->order($visit, [$test->id], 'Fever.', $this->doctor->id);
        if (! $completed) {
            return $order;
        }
        app(LabService::class)->recordResult($order->items()->first(), ['result_value' => 'Negative'], $this->doctor->id);

        return app(LabService::class)->complete($order->fresh(), $this->doctor->id);
    }

    private function prescribedVisit(): Visit
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $this->patient->id]);
        app(PrescriptionService::class)->prescribe($visit, [[
            'drug_name' => 'Amoxicillin 500mg', 'dosage' => '1 capsule', 'slots' => ['morning', 'evening'], 'days' => 5,
            'start_date' => now()->toDateString(), 'instructions' => 'After food.',
        ]], null, $this->doctor->id);

        return $visit;
    }

    public function test_shareable_lists_only_what_is_ready_and_what_this_person_may_print(): void
    {
        $released = $this->labOrder();
        $this->labOrder(completed: false);
        $visit = $this->prescribedVisit();
        Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $this->patient->id]); // nothing prescribed

        Sanctum::actingAs($this->doctor);
        $data = $this->getJson("/api/v1/patients/{$this->patient->uuid}/shareable")->assertOk()->json('data');

        $this->assertSame([1, 3, 7, 14, 30], $data['days']);
        $this->assertSame(7, $data['default_days']);
        $this->assertSame(5, $data['max_attempts']);
        $byType = collect($data['documents'])->groupBy('type');
        $this->assertSame([$released->uuid], $byType['lab_result']->pluck('uuid')->all(), 'the unreleased result is not offered');
        $this->assertSame('Malaria RDT', $byType['lab_result'][0]['title']);
        $this->assertSame('LAB-'.str_pad((string) $released->id, 6, '0', STR_PAD_LEFT), $byType['lab_result'][0]['reference']);
        $this->assertSame([$visit->uuid], $byType['prescription']->pluck('uuid')->all(), 'only the visit with something prescribed');
        $this->assertSame($visit->visit_no, $byType['prescription'][0]['reference']);
        $this->assertArrayNotHasKey('dental_quote', $byType->all(), 'no planned treatment, no quote');

        // A receptionist may print none of these, so is offered nothing.
        Sanctum::actingAs($this->user('receptionist'));
        $this->getJson("/api/v1/patients/{$this->patient->uuid}/shareable")->assertOk()->assertJsonPath('data.documents', []);
    }

    public function test_shareable_says_whether_the_link_can_be_texted_and_why_not(): void
    {
        Sanctum::actingAs($this->doctor);
        $this->getJson("/api/v1/patients/{$this->patient->uuid}/shareable")->assertOk()
            ->assertJsonPath('data.can_text', false)
            ->assertJsonPath('data.text_note', 'Text messages are off for this clinic: copy the link and code instead.');

        MessagingSettings::save($this->h->fresh(), ['sms' => true, 'events' => [], 'quiet_start' => '23:59', 'quiet_end' => '00:00']);
        $this->getJson("/api/v1/patients/{$this->patient->uuid}/shareable")->assertOk()
            ->assertJsonPath('data.can_text', false)
            ->assertJsonPath('data.text_note', 'The patient has not agreed to texts about their care: copy the link and code instead.');

        app(ConsentService::class)->record($this->patient, ['sms.care' => true]);
        $this->getJson("/api/v1/patients/{$this->patient->uuid}/shareable")->assertOk()
            ->assertJsonPath('data.can_text', true)
            ->assertJsonPath('data.text_note', null);
    }

    public function test_shareable_is_refused_without_patients_view_and_404_for_another_hospital(): void
    {
        $other = Hospital::factory()->create();
        $theirs = Patient::factory()->create(['hospital_id' => $other->id]);

        Sanctum::actingAs($this->doctor);
        $this->getJson("/api/v1/patients/{$theirs->uuid}/shareable")->assertNotFound();

        // Staff left with no role in the matrix: no patients.view.
        $blind = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor']);
        $blind->syncRoles([]);
        Sanctum::actingAs($blind->fresh());
        $this->getJson("/api/v1/patients/{$this->patient->uuid}/shareable")->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_a_link_made_from_the_phone_carries_its_qr_and_its_secrets_are_never_kept(): void
    {
        $order = $this->labOrder();
        Sanctum::actingAs($this->doctor);
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['document_type' => 'lab_result', 'document_uuid' => $order->uuid, 'days' => 3];

        $res = $this->postJson('/api/v1/patient-links', $body, $key)->assertCreated()
            ->assertJsonPath('data.link.can_revoke', true);
        $made = $res->json('data');
        $this->assertStringStartsWith('data:image/png;base64,', (string) $made['qr']);
        $this->assertNotFalse(base64_decode(Str::after($made['qr'], ','), true));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        // The link and its code in clear never sit in the idempotency store; a
        // retry makes a fresh link, and the newest one shown is the one sent.
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('idempotency_keys')->count());
        $again = $this->postJson('/api/v1/patient-links', $body, $key)->assertCreated()->json('data');
        $this->assertNotSame($made['link']['uuid'], $again['link']['uuid']);

        // Validation, as before: an expiry the clinic does not offer.
        $this->postJson('/api/v1/patient-links', ['document_type' => 'lab_result', 'document_uuid' => $order->uuid, 'days' => 5])
            ->assertStatus(422)->assertJsonValidationErrors(['days']);
    }

    public function test_the_list_searches_like_the_web_and_says_who_may_withdraw(): void
    {
        $order = $this->labOrder();
        $made = app(PatientLinkService::class)->issue(SharedDocumentType::LabResult, $order, $this->doctor);
        $someone = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Peter', 'last_name' => 'Okello']);
        app(PatientLinkService::class)->issue(SharedDocumentType::LabResult, $this->labOrder(patient: $someone), $this->doctor);

        Sanctum::actingAs($this->doctor);
        $this->getJson('/api/v1/patient-links?q=Nakato')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $made['link']->uuid)->assertJsonPath('data.0.can_revoke', true);
        $this->getJson('/api/v1/patient-links?q=PT-2026-000777')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/patient-links?q='.urlencode($made['link']->reference))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/patient-links')->assertOk()->assertJsonPath('meta.total', 2);

        // A receptionist sees the list (patients.view) but may not withdraw a lab result's link.
        Sanctum::actingAs($this->user('receptionist'));
        $this->getJson('/api/v1/patient-links?q=Nakato')->assertOk()->assertJsonPath('data.0.can_revoke', false);
        $this->deleteJson("/api/v1/patient-links/{$made['link']->uuid}")->assertForbidden();

        // A withdrawn link can no longer be withdrawn.
        Sanctum::actingAs($this->doctor);
        $this->deleteJson("/api/v1/patient-links/{$made['link']->uuid}", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.state', 'revoked')->assertJsonPath('data.can_revoke', false);
    }

    public function test_the_open_log_says_who_withdrew_it_and_what_happened_to_the_text(): void
    {
        MessagingSettings::save($this->h->fresh(), ['sms' => true, 'events' => [], 'quiet_start' => '23:59', 'quiet_end' => '00:00']);
        $made = app(PatientLinkService::class)->issue(SharedDocumentType::LabResult, $this->labOrder(), $this->doctor, 7, true);
        app(PatientLinkService::class)->revoke($made['link'], $this->doctor);

        Sanctum::actingAs($this->doctor);
        $this->getJson("/api/v1/patient-links/{$made['link']->uuid}")->assertOk()
            ->assertJsonPath('data.state', 'revoked')
            ->assertJsonPath('data.revoked_by', $this->doctor->name)
            ->assertJsonPath('data.message.status', 'skipped')
            ->assertJsonPath('data.message.skip_reason', 'no_consent')
            ->assertJsonPath('data.opens', []);
    }
}
