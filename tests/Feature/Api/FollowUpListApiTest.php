<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Livewire\Appointments\Recalls;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientMessage;
use App\Models\User;
use App\Models\Visit;
use App\Services\Messaging\ConsentService;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Recalls and reviews due for the app (GET follow-ups, POST follow-ups/text;
 * docs/api/follow-up.md): the web page's rows, search and flags, with what the
 * desk needs before calling.
 */
class FollowUpListApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $h;

    private User $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->h = $this->hospital();
        $this->withHospital($this->h);
        $this->desk = $this->staff('receptionist');
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function recall(int $daysFromToday, array $attrs = [], ?Hospital $h = null): Patient
    {
        return Patient::factory()->create($attrs + [
            'hospital_id' => ($h ?? $this->h)->id,
            'next_recall_on' => Carbon::today()->addDays($daysFromToday)->toDateString(),
        ]);
    }

    private function review(Carbon $at, AppointmentStatus $status = AppointmentStatus::Scheduled, array $patient = []): Appointment
    {
        $doctor = $this->staff('doctor');
        $p = Patient::factory()->create($patient + ['hospital_id' => $this->h->id]);
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $p->id, 'doctor_user_id' => $doctor->id, 'created_at' => now()->subDays(20)]);
        $a = new Appointment([
            'uuid' => (string) Str::uuid(), 'patient_id' => $p->id, 'origin_visit_id' => $visit->id,
            'doctor_user_id' => $doctor->id, 'scheduled_at' => $at, 'ends_at' => $at->copy()->addMinutes(15),
            'duration_minutes' => 15, 'source' => 'walk_in', 'status' => $status, 'reason' => 'Review',
        ]);
        $a->forceFill(['hospital_id' => $this->h->id])->save();

        return $a;
    }

    public function test_each_row_says_what_is_due_how_overdue_whether_texts_are_agreed_and_when_last_seen(): void
    {
        $overdue = $this->recall(-20, ['first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0772 123 456']);
        app(ConsentService::class)->record($overdue, ['sms.care' => true], 'desk');
        $seenAt = now()->subDays(200)->startOfMinute();
        Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $overdue->id, 'created_at' => $seenAt]);
        $soon = $this->recall(3, ['first_name' => 'Ruth', 'last_name' => 'Kobusingye']);
        app(ConsentService::class)->record($soon, ['sms.marketing' => true], 'desk');

        Sanctum::actingAs($this->desk);
        $res = $this->getJson('/api/v1/follow-ups?show=recalls')->assertOk()
            ->assertJsonPath('meta.show', 'recalls')
            ->assertJsonPath('meta.search', '')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.recalls', 2)
            ->assertJsonPath('data.0.kind', 'recall')
            ->assertJsonPath('data.0.id', $overdue->id)
            ->assertJsonPath('data.0.what', 'Dental check-up')
            ->assertJsonPath('data.0.due_on', Carbon::today()->subDays(20)->toDateString())
            ->assertJsonPath('data.0.flag', ['label' => '20 days overdue', 'tone' => 'warn'])
            ->assertJsonPath('data.0.patient.server_id', $overdue->id)
            ->assertJsonPath('data.0.patient.first_name', 'Amina')
            ->assertJsonPath('data.0.patient.last_name', 'Nakato')
            ->assertJsonPath('data.0.patient.name', 'Amina Nakato')
            ->assertJsonPath('data.0.patient.phone', '0772 123 456')
            ->assertJsonPath('data.0.patient.sms_consent', true)
            ->assertJsonPath('data.0.patient.last_visit_at', $seenAt->toIso8601String())
            ->assertJsonPath('data.1.flag', null)
            // A yes to other texts is not a yes to texts about care.
            ->assertJsonPath('data.1.patient.sms_consent', false)
            ->assertJsonPath('data.1.patient.last_visit_at', null);
        $this->assertSame(['kind', 'id', 'key', 'due_on', 'what', 'flag', 'patient', 'appointment'], array_keys($res->json('data.0')));

        $missed = $this->review(Carbon::today()->subDays(4)->setTime(10, 0), AppointmentStatus::NoShow);
        $gone = $this->review(Carbon::today()->subDays(6)->setTime(10, 0));
        $week = $this->review(Carbon::today()->addDays(2)->setTime(10, 0));

        $this->getJson('/api/v1/follow-ups?show=missed')->assertOk()
            ->assertJsonPath('data.0.id', $missed->id)
            ->assertJsonPath('data.0.what', 'Review')
            ->assertJsonPath('data.0.flag', ['label' => 'Did not come', 'tone' => 'danger'])
            ->assertJsonPath('data.0.patient.server_id', $missed->patient_id)
            ->assertJsonPath('data.0.appointment.uuid', $missed->uuid)
            ->assertJsonPath('data.1.id', $gone->id)
            ->assertJsonPath('data.1.flag', ['label' => 'Not attended', 'tone' => 'danger']);
        $this->getJson('/api/v1/follow-ups?show=week')->assertOk()
            ->assertJsonPath('data.0.id', $week->id)
            ->assertJsonPath('data.0.flag', null);

        // The web page flags the same rows the same way.
        Livewire::actingAs($this->desk)->test(Recalls::class)->set('show', 'recalls')->assertSee('20 days overdue');
        Livewire::actingAs($this->desk)->test(Recalls::class)->set('show', 'missed')->assertSee('Did not come')->assertSee('Not attended');
    }

    public function test_one_day_overdue_reads_in_the_singular(): void
    {
        $this->recall(-1);
        Sanctum::actingAs($this->desk);

        $this->getJson('/api/v1/follow-ups?show=recalls')->assertOk()->assertJsonPath('data.0.flag.label', '1 day overdue');
    }

    public function test_the_search_finds_a_full_name_a_number_or_a_phone_on_the_app_and_the_web_alike(): void
    {
        $amina = $this->recall(-5, ['first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0772123456']);
        $this->recall(-6, ['first_name' => 'Ruth', 'last_name' => 'Kobusingye', 'phone_1' => '0701 999 888']);
        $review = $this->review(Carbon::today()->addDay()->setTime(9, 0), patient: ['first_name' => 'John', 'last_name' => 'Okello']);

        Sanctum::actingAs($this->desk);
        $this->getJson('/api/v1/follow-ups?show=recalls&q=amina%20nakato')->assertOk()
            ->assertJsonPath('meta.search', 'amina nakato')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $amina->id)
            // The counts are of the whole lists, as on the web.
            ->assertJsonPath('meta.counts.recalls', 2);
        $this->getJson('/api/v1/follow-ups?show=recalls&q='.urlencode($amina->patient_no))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/follow-ups?show=recalls&q=%2B256772123456')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $amina->id);
        $this->getJson('/api/v1/follow-ups?show=week&q=okello')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $review->id);
        $this->getJson('/api/v1/follow-ups?show=week&q=amina')->assertOk()->assertJsonPath('meta.total', 0);

        Livewire::actingAs($this->desk)->test(Recalls::class)->set('show', 'recalls')->set('search', 'Amina Nakato')
            ->assertSee('Amina Nakato')->assertDontSee('Kobusingye');
    }

    public function test_who_may_read_and_who_may_text(): void
    {
        $due = $this->recall(-3);
        $this->getJson('/api/v1/follow-ups')->assertUnauthorized();

        Sanctum::actingAs($this->staff('pharmacist'));
        $this->getJson('/api/v1/follow-ups?show=recalls')->assertForbidden()->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($this->staff('nurse'));
        $this->getJson('/api/v1/follow-ups?show=recalls')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/follow-ups/text', ['kind' => 'recall', 'id' => $due->id])->assertForbidden();
        $this->assertSame(0, PatientMessage::query()->count());
    }

    public function test_another_hospitals_patients_never_show_and_cannot_be_texted(): void
    {
        $other = $this->hospital();
        $foreign = $this->recall(-3, ['first_name' => 'Foreign'], $other);
        $this->withHospital($this->h);

        Sanctum::actingAs($this->desk);
        $this->getJson('/api/v1/follow-ups?show=recalls')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.counts.recalls', 0);
        $this->postJson('/api/v1/follow-ups/text', ['kind' => 'recall', 'id' => $foreign->id])->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertSame(0, PatientMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_what_is_sent_is_checked(): void
    {
        Sanctum::actingAs($this->desk);

        $this->getJson('/api/v1/follow-ups?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->postJson('/api/v1/follow-ups/text', ['kind' => 'birthday', 'id' => 1])->assertStatus(422)->assertJsonValidationErrors(['kind']);
        $this->postJson('/api/v1/follow-ups/text', ['kind' => 'recall'])->assertStatus(422)->assertJsonValidationErrors(['id']);
        // A list the server does not know is this week's reviews, as on the web.
        $this->getJson('/api/v1/follow-ups?show=everything')->assertOk()->assertJsonPath('meta.show', 'week');
    }

    public function test_a_text_sent_again_is_one_text(): void
    {
        MessagingSettings::save($this->h->fresh(), ['sms' => true, 'events' => [], 'quiet_start' => '23:59', 'quiet_end' => '00:00']);
        app(MessageCredits::class)->post($this->h->id, 'topup', 50, 'TEST');
        $due = $this->recall(-2, ['phone_1' => '0772 123 456']);
        app(ConsentService::class)->record($due, ['sms.care' => true], 'desk');

        Sanctum::actingAs($this->desk);
        $first = $this->postJson('/api/v1/follow-ups/text', ['kind' => 'recall', 'id' => $due->id], ['Idempotency-Key' => 'text-1'])
            ->assertOk()->assertJsonPath('data.sent', true)->assertJsonPath('message', 'Text sent to '.$due->full_name.'.');
        $again = $this->postJson('/api/v1/follow-ups/text', ['kind' => 'recall', 'id' => $due->id], ['Idempotency-Key' => 'text-1'])
            ->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json(), $again->json());
        $this->assertSame(1, PatientMessage::query()->where('patient_id', $due->id)->count());
    }

    public function test_consent_and_last_visit_cost_the_same_queries_for_two_rows_or_six(): void
    {
        Sanctum::actingAs($this->desk);
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/follow-ups?show=recalls')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach ([-2, -3] as $d) {
            Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $this->recall($d)->id, 'created_at' => now()->subYear()]);
        }
        $queries(); // the first request also resolves the hospital and the permissions
        $two = $queries();
        foreach ([-4, -5, -6, -7] as $d) {
            app(ConsentService::class)->record($this->recall($d), ['sms.care' => true], 'desk');
        }
        $six = $queries();

        $this->assertSame($two, $six, 'no query per row');
    }
}
