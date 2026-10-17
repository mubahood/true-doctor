<?php

namespace Tests\Feature;

use App\Enums\MessageStatus;
use App\Enums\SharedDocumentType;
use App\Livewire\PatientLinks\Index;
use App\Livewire\PatientLinks\Panels\Share;
use App\Models\DocumentVerification;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Models\PatientMessage;
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
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Patient links and the verification QR (roadmap 05 §4, §9): expiry,
 * withdrawal, the unlock code and date of birth, the lock after five tries,
 * the open log, consent on the text, permissions, tenancy, the screens and the
 * API; and the verification page that never shows a document's contents.
 */
class PatientLinksTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['name' => 'Kato Medical Centre', 'currency' => 'UGX', 'timezone' => 'Africa/Kampala']);
        $this->h->forceFill(['settings' => ['profile' => ['phone' => '+256 700 111 222']]])->save();
        app(CurrentHospital::class)->set($this->h->id);

        $this->doctor = $this->user('doctor', $this->h);
        $this->patient = Patient::factory()->create([
            'hospital_id' => $this->h->id, 'first_name' => 'Amina', 'last_name' => 'Nakato',
            'dob' => '1990-03-14', 'phone_1' => '0772 123 456',
        ]);
    }

    private function user(string $role, Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function completedLab(?Patient $patient = null): LabOrder
    {
        $patient ??= $this->patient;
        $visit = Visit::factory()->create(['hospital_id' => $patient->hospital_id, 'patient_id' => $patient->id]);
        $test = LabTest::factory()->create(['hospital_id' => $patient->hospital_id]);
        $order = app(LabService::class)->order($visit, [$test->id], 'Query malaria.', $this->doctor->id);
        app(LabService::class)->recordResult($order->items()->first(), ['result_value' => 'Negative'], $this->doctor->id);

        return app(LabService::class)->complete($order->fresh(), $this->doctor->id);
    }

    /** @return array{link:PatientLink,url:string,code:string,token:string} */
    private function issue(?LabOrder $order = null, int $days = 7, bool $send = false): array
    {
        $made = app(PatientLinkService::class)->issue(SharedDocumentType::LabResult, $order ?? $this->completedLab(), $this->doctor, $days, $send);
        $made['token'] = Str::afterLast($made['url'], '/');

        return $made;
    }

    // ── Opening a link ───────────────────────────────────────────────────

    public function test_the_link_is_short_and_only_hashes_are_stored(): void
    {
        $made = $this->issue();

        $this->assertMatchesRegularExpression('#/p/[A-Za-z0-9]{24}$#', $made['url']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $made['code']);
        $this->assertSame(hash('sha256', $made['token']), $made['link']->token_hash);
        $this->assertStringNotContainsString($made['code'], $made['link']->code_hash);
        $this->assertSame('LAB-'.str_pad((string) $made['link']->document_id, 6, '0', STR_PAD_LEFT), $made['link']->reference);
        $this->assertTrue($made['link']->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
    }

    public function test_the_page_asks_for_the_code_and_says_nothing_about_the_patient_until_unlocked(): void
    {
        $made = $this->issue();

        $this->get($made['url'])->assertOk()
            ->assertSee('Kato Medical Centre')->assertSee('Code or date of birth')
            ->assertDontSee('Amina')->assertDontSee('Nakato');

        $this->get(route('patient-link.document', ['token' => $made['token']]))->assertRedirect($made['url']);
        $this->assertSame(1, PatientLinkOpen::query()->where('event', 'viewed')->count());
    }

    public function test_the_code_unlocks_it_and_the_document_opens_inline(): void
    {
        $made = $this->issue();

        $this->post(route('patient-link.unlock', ['token' => $made['token']]), ['answer' => $made['code']])->assertRedirect($made['url']);
        $this->get($made['url'])->assertOk()->assertSee('Hello Amina')->assertSee('Open the document');

        $pdf = $this->get(route('patient-link.document', ['token' => $made['token']]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('inline', (string) $pdf->headers->get('content-disposition'));

        $link = $made['link']->fresh();
        $this->assertSame(1, $link->opens_count);
        $this->assertNotNull($link->last_opened_at);
        $this->assertSame(['unlocked', 'downloaded'], PatientLinkOpen::query()->orderBy('id')->pluck('event')->all());
        $this->assertNotNull(PatientLinkOpen::query()->where('event', 'unlocked')->value('ip'));
    }

    public function test_the_date_of_birth_unlocks_it_too(): void
    {
        $made = $this->issue();

        $this->post(route('patient-link.unlock', ['token' => $made['token']]), ['answer' => '14/03/1990']);
        $this->get(route('patient-link.document', ['token' => $made['token']]))->assertOk();
    }

    public function test_five_wrong_answers_lock_it_for_good(): void
    {
        $made = $this->issue();
        $url = route('patient-link.unlock', ['token' => $made['token']]);

        for ($i = 1; $i <= 4; $i++) {
            $this->post($url, ['answer' => '000000'])->assertSessionHasErrors('answer');
        }
        $this->post($url, ['answer' => '01/01/1980']);

        $link = $made['link']->fresh();
        $this->assertSame(PatientLink::LOCKED, $link->state());
        $this->assertSame(1, PatientLinkOpen::query()->where('event', 'locked')->count());

        // Even the right code no longer opens it, and the page gives the clinic's number.
        $this->post($url, ['answer' => $made['code']]);
        $this->get(route('patient-link.document', ['token' => $made['token']]))->assertRedirect();
        $this->get($made['url'])->assertStatus(410)->assertSee('This link is locked')->assertSee('+256 700 111 222');
    }

    public function test_an_expired_link_refuses_and_logs_it(): void
    {
        $made = $this->issue(days: 1);
        $this->travel(2)->days();

        $this->get($made['url'])->assertStatus(410)->assertSee('This link has expired');
        $this->post(route('patient-link.unlock', ['token' => $made['token']]), ['answer' => $made['code']]);
        $this->get(route('patient-link.document', ['token' => $made['token']]))->assertRedirect();
        $this->assertSame(2, PatientLinkOpen::query()->where('event', 'refused')->count());
    }

    public function test_a_withdrawn_link_stops_at_once_even_in_a_browser_that_unlocked_it(): void
    {
        $made = $this->issue();
        $this->post(route('patient-link.unlock', ['token' => $made['token']]), ['answer' => $made['code']]);

        app(PatientLinkService::class)->revoke($made['link'], $this->doctor);

        $this->get(route('patient-link.document', ['token' => $made['token']]))->assertRedirect();
        $this->get($made['url'])->assertStatus(410)->assertSee('withdrawn')->assertSee('+256 700 111 222');
    }

    public function test_an_unknown_token_is_a_plain_not_found(): void
    {
        $this->get('/p/'.str_repeat('a', 24))->assertNotFound()->assertSee('This link does not work');
        $this->get('/p/short')->assertNotFound();
    }

    public function test_a_result_the_lab_has_not_released_cannot_be_sent(): void
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $this->patient->id]);
        $test = LabTest::factory()->create(['hospital_id' => $this->h->id]);
        $order = app(LabService::class)->order($visit, [$test->id], null, $this->doctor->id);

        $this->expectException(ValidationException::class);
        app(PatientLinkService::class)->issue(SharedDocumentType::LabResult, $order, $this->doctor);
    }

    // ── The text message ─────────────────────────────────────────────────

    public function test_the_link_and_code_are_texted_when_the_patient_said_yes(): void
    {
        MessagingSettings::save($this->h->fresh(), ['sms' => true, 'events' => [], 'quiet_start' => '23:59', 'quiet_end' => '00:00']);
        app(ConsentService::class)->record($this->patient, ['sms.care' => true]);
        app(\App\Services\Messaging\MessageCredits::class)->post($this->h->id, 'topup', 10, 'TEST');

        $made = $this->issue(send: true);

        $message = PatientMessage::query()->firstOrFail();
        $this->assertSame('document_link', $message->event);
        $this->assertNotSame(MessageStatus::Skipped, $message->status, (string) $message->skip_reason);
        $this->assertStringContainsString($made['url'], $message->body);
        $this->assertStringContainsString($made['code'], $message->body);
        $this->assertSame($message->id, $made['link']->fresh()->patient_message_id);
    }

    public function test_without_consent_nothing_is_texted_but_the_link_is_made(): void
    {
        MessagingSettings::save($this->h->fresh(), ['sms' => true, 'events' => [], 'quiet_start' => '23:59', 'quiet_end' => '00:00']);

        $made = $this->issue(send: true);

        $this->assertSame(MessageStatus::Skipped, $made['message']?->status);
        $this->assertSame('no_consent', $made['message']?->skip_reason);
        $this->assertTrue($made['link']->isActive());
    }

    // ── The screens ──────────────────────────────────────────────────────

    public function test_staff_make_a_link_from_the_document_and_see_the_code_once(): void
    {
        $order = $this->completedLab();
        $this->actingAs($this->doctor);

        Livewire::test(Share::class, ['type' => 'lab_result', 'uuid' => $order->uuid])
            ->assertSee('Send to patient')
            ->call('open')->assertSet('showShare', true)
            ->set('days', 3)
            ->call('share')
            ->assertHasNoErrors()
            ->assertSee('/p/')
            ->assertSet('result.code', fn ($code) => preg_match('/^\d{6}$/', (string) $code) === 1);

        $link = PatientLink::query()->firstOrFail();
        $this->assertTrue($link->expires_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
    }

    public function test_the_button_is_not_offered_to_someone_who_may_not_print_the_document(): void
    {
        $order = $this->completedLab();
        $this->actingAs($this->user('receptionist', $this->h));

        Livewire::test(Share::class, ['type' => 'lab_result', 'uuid' => $order->uuid])
            ->assertDontSee('Send to patient')
            ->call('open')->assertForbidden();
    }

    public function test_the_links_page_lists_shows_who_opened_and_withdraws(): void
    {
        $made = $this->issue();
        app(PatientLinkService::class)->record($made['link'], PatientLinkOpen::VIEWED, '102.85.14.7', 'Android phone');
        $this->actingAs($this->doctor);

        Livewire::test(Index::class)
            ->assertSee('Lab result')->assertSee('Amina Nakato')->assertSee('Active')
            ->call('peek', $made['link']->id)->assertSee('102.85.14.7')->assertSee('Link opened')
            ->call('revoke', $made['link']->id);

        $this->assertSame(PatientLink::REVOKED, $made['link']->fresh()->state());
        $this->assertSame($this->doctor->id, $made['link']->fresh()->revoked_by);

        Livewire::test(Index::class, ['state' => 'active'])->assertDontSee('Amina Nakato');
    }

    public function test_the_links_page_never_shows_another_hospitals_links(): void
    {
        $other = Hospital::factory()->create();
        $theirs = Patient::factory()->create(['hospital_id' => $other->id, 'first_name' => 'Zawadi', 'last_name' => 'Okello']);
        app(CurrentHospital::class)->set($other->id);
        $theirLink = $this->issue($this->completedLab($theirs))['link'];
        app(CurrentHospital::class)->set($this->h->id);

        $this->actingAs($this->doctor);
        $this->get(route('admin.patient-links.index'))->assertOk()->assertDontSee('Zawadi');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(Index::class)->call('peek', $theirLink->id);
    }

    // ── The API ──────────────────────────────────────────────────────────

    public function test_the_api_makes_lists_reads_and_withdraws_a_link(): void
    {
        $order = $this->completedLab();
        Sanctum::actingAs($this->doctor);
        $key = (string) Str::uuid();

        $made = $this->postJson('/api/v1/patient-links', ['document_type' => 'lab_result', 'document_uuid' => $order->uuid, 'days' => 14], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('data.link.state', 'active')
            ->assertJsonPath('data.link.patient.uuid', $this->patient->uuid)
            ->json('data');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $made['code']);

        // A retry after a lost reply replays the answer and makes no second link.
        $this->postJson('/api/v1/patient-links', ['document_type' => 'lab_result', 'document_uuid' => $order->uuid, 'days' => 14], ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.code', $made['code']);
        $this->assertSame(1, PatientLink::query()->count());

        $uuid = $made['link']['uuid'];
        $this->getJson('/api/v1/patient-links?patient='.$this->patient->uuid)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $uuid);
        $this->getJson("/api/v1/patient-links/{$uuid}")->assertOk()->assertJsonPath('data.opens', []);
        $this->deleteJson("/api/v1/patient-links/{$uuid}")->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->getJson('/api/v1/patient-links?state=active')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_api_refuses_a_role_that_may_not_print_the_document(): void
    {
        $order = $this->completedLab();
        Sanctum::actingAs($this->user('receptionist', $this->h));

        $this->postJson('/api/v1/patient-links', ['document_type' => 'lab_result', 'document_uuid' => $order->uuid])->assertForbidden();
    }

    public function test_the_api_says_why_a_document_cannot_be_sent_yet(): void
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $this->patient->id]);
        Sanctum::actingAs($this->doctor);

        $this->postJson('/api/v1/patient-links', ['document_type' => 'prescription', 'document_uuid' => $visit->uuid])
            ->assertStatus(422)->assertJsonPath('errors.document.0', 'Nothing has been prescribed on this visit.');
    }

    public function test_the_api_cannot_reach_another_hospitals_document_or_link(): void
    {
        $other = Hospital::factory()->create();
        $theirs = Patient::factory()->create(['hospital_id' => $other->id]);
        app(CurrentHospital::class)->set($other->id);
        $theirOrder = $this->completedLab($theirs);
        $theirLink = $this->issue($theirOrder)['link'];
        app(CurrentHospital::class)->set($this->h->id);

        Sanctum::actingAs($this->doctor);
        $this->postJson('/api/v1/patient-links', ['document_type' => 'lab_result', 'document_uuid' => $theirOrder->uuid])->assertNotFound();
        $this->getJson("/api/v1/patient-links/{$theirLink->uuid}")->assertNotFound();
        $this->deleteJson("/api/v1/patient-links/{$theirLink->uuid}")->assertNotFound();
        $this->assertNull($theirLink->fresh()->revoked_at);
    }

    public function test_a_prescription_can_be_sent_and_printed(): void
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $this->patient->id]);
        app(PrescriptionService::class)->prescribe($visit, [[
            'drug_name' => 'Amoxicillin 500mg', 'dosage' => '1 capsule', 'slots' => ['morning', 'evening'], 'days' => 5,
            'start_date' => now()->toDateString(), 'instructions' => 'After food.',
        ]], null, $this->doctor->id);

        $this->actingAs($this->doctor);
        $this->get(route('admin.visits.prescription', $visit))->assertOk()->assertHeader('content-type', 'application/pdf');

        $made = app(PatientLinkService::class)->issue(SharedDocumentType::Prescription, $visit, $this->doctor);
        $this->assertSame($visit->visit_no, $made['link']->reference);
    }

    // ── The verification QR ──────────────────────────────────────────────

    public function test_every_printed_document_carries_a_code_that_verifies_without_its_contents(): void
    {
        $order = $this->completedLab();
        $this->actingAs($this->doctor);

        $this->get(route('admin.lab-orders.pdf', $order))->assertOk();
        $row = DocumentVerification::query()->firstOrFail();
        $this->assertSame('Laboratory report', $row->title);
        $this->assertSame('A. N.', $row->patient_initials);

        // Printed again the same day: the same code, not a second row.
        $this->get(route('admin.lab-orders.pdf', $order))->assertOk();
        $this->assertSame(1, DocumentVerification::query()->count());

        auth()->logout();
        $this->get(route('verify.show', ['code' => $row->code]))->assertOk()
            ->assertSee('Genuine document')
            ->assertSee('This is a genuine laboratory report from Kato Medical Centre')
            ->assertSee('A. N.')
            ->assertSee('LAB-')
            ->assertDontSee('Amina')->assertDontSee('Nakato')->assertDontSee('Negative');
        $this->assertSame(1, $row->fresh()->checks_count);
    }

    public function test_the_code_is_on_the_rendered_document(): void
    {
        $order = $this->completedLab()->load(['items', 'patient', 'orderedBy']);
        $html = view('pdf.lab-result', ['order' => $order, 'hospital' => $this->h])->render();
        $row = DocumentVerification::query()->firstOrFail();

        $this->assertStringContainsString(DocumentVerification::display($row->code), $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_a_typed_code_is_found_however_it_is_written_and_a_wrong_one_is_not(): void
    {
        app(\App\Services\DocumentVerifier::class)->stamp('Invoice', 'INV-1', $this->patient);
        $code = DocumentVerification::query()->value('code');

        $this->get('/v?code='.strtolower(substr($code, 0, 4).'-'.substr($code, 4)))->assertRedirect(route('verify.show', ['code' => $code]));
        $this->get('/v?code=nope')->assertRedirect(route('verify.form'));
        $this->get('/v')->assertOk()->assertSee('Verify a document');
        $this->get('/v/ZZZZZZZZ')->assertNotFound()->assertSee('No document has this code');
    }
}
