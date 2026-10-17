<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\OwnerSummary;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\SmsResult;
use App\Services\Messaging\SmsTransport;
use App\Services\OwnerSummaryService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The owner's daily summary (roadmap 09 §2): who may have it, what it says,
 * that it goes once a day at the owner's time and never in quiet hours, that
 * a failure is retried a few times and no more, that a text costs a message,
 * and the settings card and API.
 */
class OwnerSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    private User $owner;

    /** @var object{sent: list<array{0:string,1:string}>, answer: ?SmsResult} */
    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['name' => 'Kato Medical Centre', 'currency' => 'UGX', 'timezone' => 'Africa/Kampala']);
        $this->other = Hospital::factory()->create(['name' => 'Other Clinic', 'timezone' => 'Africa/Kampala']);
        $this->owner = $this->user('hospital_admin', $this->h, ['email' => 'owner@kato.test', 'phone' => '0772 111 222']);
        // 08:00 in Kampala on 17 October: yesterday is the 16th.
        $this->travelTo(Carbon::parse('2026-10-17 08:00:00', 'Africa/Kampala')->utc());

        $this->gateway = new class implements SmsTransport
        {
            public array $sent = [];

            public ?SmsResult $answer = null;

            public function name(): string
            {
                return 'fake';
            }

            public function live(): bool
            {
                return true;
            }

            public function send(string $to, string $body): SmsResult
            {
                $this->sent[] = [$to, $body];

                return $this->answer ?? SmsResult::sent('ATX-'.count($this->sent));
            }
        };
        $this->app->instance(SmsTransport::class, $this->gateway);
    }

    private function user(string $role, Hospital $h, array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function service(): OwnerSummaryService
    {
        return app(OwnerSummaryService::class);
    }

    private function optIn(string $channel = 'email', string $at = '07:30', ?User $owner = null): void
    {
        $this->service()->savePreferences($owner ?? $this->owner, ['enabled' => true, 'channel' => $channel, 'at' => $at]);
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function emails(): array
    {
        return array_map(fn ($m) => $m->getOriginalMessage(), iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
    }

    /** A paid visit on the 16th (Kampala), in $h. */
    private function dayOfWork(Hospital $h, string $paid = '25000.00'): void
    {
        $at = Carbon::parse('2026-10-16 10:00:00', 'Africa/Kampala')->utc();
        app(CurrentHospital::class)->within($h->id, function () use ($h, $at, $paid) {
            $patient = Patient::factory()->create(['hospital_id' => $h->id]);
            $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id, 'created_at' => $at]);
            $invoice = DB::table('invoices')->insertGetId([
                'uuid' => (string) Str::uuid(), 'hospital_id' => $h->id, 'invoice_no' => 'INV-'.Str::random(6),
                'visit_id' => $visit->id, 'patient_id' => $patient->id, 'currency' => 'UGX',
                'subtotal' => '40000.00', 'tax_total' => '0', 'discount' => '0', 'total' => '40000.00',
                'amount_paid' => $paid, 'balance' => bcsub('40000.00', $paid, 2), 'status' => 'partially_paid',
                'issued_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('payments')->insert([
                'uuid' => (string) Str::uuid(), 'hospital_id' => $h->id, 'invoice_id' => $invoice, 'patient_id' => $patient->id,
                'method' => PaymentMethod::MobileMoney->value, 'amount' => $paid, 'balance_after' => bcsub('40000.00', $paid, 2),
                'created_at' => $at,
            ]);
        });
    }

    // ── Who, and the choice ─────────────────────────────────────────────

    public function test_it_is_off_until_the_owner_turns_it_on(): void
    {
        $this->assertSame(['enabled' => false, 'channel' => 'email', 'at' => '07:30'], $this->service()->preferences($this->owner));
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->service()->sendDue());
        $this->assertSame(0, OwnerSummary::query()->withoutGlobalScopes()->count());
    }

    public function test_only_the_hospitals_administrator_can_have_it(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->savePreferences($this->user('accountant', $this->h), ['enabled' => true, 'channel' => 'email', 'at' => '07:30']);
    }

    public function test_a_text_needs_a_phone_and_the_time_must_be_a_time(): void
    {
        $noPhone = $this->user('hospital_admin', $this->h, ['phone' => null]);
        try {
            $this->service()->savePreferences($noPhone, ['enabled' => true, 'channel' => 'sms', 'at' => '07:30']);
            $this->fail('A text to nobody was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('channel', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->service()->savePreferences($this->owner, ['enabled' => true, 'channel' => 'email', 'at' => '25:00']);
    }

    // ── What it says ────────────────────────────────────────────────────

    public function test_it_tells_yesterday_in_figures_and_only_this_hospitals(): void
    {
        $this->dayOfWork($this->h, '25000.00');
        $this->dayOfWork($this->other, '77000.00');

        $s = $this->service()->compose($this->h, $this->owner, '2026-10-16');
        $text = implode("\n", $s['lines']);

        $this->assertSame(1, $s['figures']['patients_seen']);
        $this->assertSame('25000.00', $s['figures']['money_in']);
        $this->assertSame('15000.00', $s['figures']['left_unpaid']);
        $this->assertStringContainsString('Kato Medical Centre, Fri 16 Oct 2026', $text);
        $this->assertStringContainsString('Mobile money', $text);
        $this->assertStringContainsString('25,000', $text);
        $this->assertStringNotContainsString('77,000', $text);
        $this->assertStringContainsString('Your day at Kato Medical Centre', $s['subject']);
    }

    // ── Sending ─────────────────────────────────────────────────────────

    public function test_it_goes_once_a_day_by_email_at_the_owners_time(): void
    {
        $this->dayOfWork($this->h);
        $this->optIn('email', '07:30');

        $this->assertSame(['sent' => 1, 'failed' => 0], $this->service()->sendDue());
        $this->artisan('owners:daily-summary')->assertExitCode(0); // again: nothing new

        $rows = OwnerSummary::query()->withoutGlobalScopes()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('sent', $rows[0]->status);
        $this->assertSame('2026-10-16', $rows[0]->summary_date->toDateString());
        $this->assertSame('owner@kato.test', $rows[0]->recipient);

        $emails = $this->emails();
        $this->assertCount(1, $emails);
        $this->assertSame('Your day at Kato Medical Centre: Fri 16 Oct', $emails[0]->getSubject());
        $this->assertStringContainsString('Patients seen: 1.', (string) $emails[0]->getTextBody());
    }

    public function test_not_before_the_chosen_time(): void
    {
        $this->optIn('email', '09:00');
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->service()->sendDue());

        $this->travelTo(Carbon::parse('2026-10-17 09:05:00', 'Africa/Kampala')->utc());
        $this->assertSame(['sent' => 1, 'failed' => 0], $this->service()->sendDue());
    }

    public function test_never_in_the_hospitals_quiet_hours(): void
    {
        MessagingSettings::save($this->h->fresh(), ['sms' => false, 'events' => [], 'quiet_start' => '21:00', 'quiet_end' => '08:30']);
        $this->optIn('email', '07:30');

        $this->assertSame(['sent' => 0, 'failed' => 0], $this->service()->sendDue());

        $this->travelTo(Carbon::parse('2026-10-17 08:31:00', 'Africa/Kampala')->utc());
        $this->assertSame(['sent' => 1, 'failed' => 0], $this->service()->sendDue());
    }

    public function test_a_time_chosen_inside_the_quiet_hours_goes_when_they_end(): void
    {
        $this->optIn('email', '22:30'); // quiet 21:00 to 07:00 by default

        $this->travelTo(Carbon::parse('2026-10-17 06:55:00', 'Africa/Kampala')->utc());
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->service()->sendDue());

        $this->travelTo(Carbon::parse('2026-10-17 07:05:00', 'Africa/Kampala')->utc());
        $this->assertSame(['sent' => 1, 'failed' => 0], $this->service()->sendDue());
        $this->travelTo(Carbon::parse('2026-10-17 20:59:00', 'Africa/Kampala')->utc());
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->service()->sendDue(), 'once that day');

        $this->assertSame(['2026-10-16'], OwnerSummary::query()->withoutGlobalScopes()->get()->map(fn ($r) => $r->summary_date->toDateString())->all());
    }

    public function test_a_suspended_hospital_hears_nothing(): void
    {
        $this->optIn('email');
        $this->h->forceFill(['status' => 'suspended'])->save();

        $this->assertSame(['sent' => 0, 'failed' => 0], $this->service()->sendDue());
    }

    public function test_a_text_costs_messages_on_the_online_service_and_a_failure_is_retried_three_times(): void
    {
        $this->optIn('sms');
        app(MessageCredits::class)->post($this->h->id, 'topup', 10, 'MM-1');

        $this->gateway->answer = SmsResult::failed('Gateway down', true);
        for ($i = 0; $i < 5; $i++) {
            $this->service()->sendDue();
        }
        $row = OwnerSummary::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertSame(3, $row->attempts, 'tried three times, then left alone');
        $this->assertCount(3, $this->gateway->sent);
        $this->assertSame(10, app(MessageCredits::class)->balance($this->h->id), 'every failed text gave its messages back');
        $this->assertSame('+256772111222', $this->gateway->sent[0][0]);

        // The next day, the gateway is back.
        $this->gateway->answer = null;
        $this->travelTo(Carbon::parse('2026-10-18 08:00:00', 'Africa/Kampala')->utc());
        $this->assertSame(['sent' => 1, 'failed' => 0], $this->service()->sendDue());
        $this->assertLessThan(10, app(MessageCredits::class)->balance($this->h->id));
    }

    public function test_no_messages_left_means_no_text_and_says_why(): void
    {
        $this->optIn('sms');

        $this->service()->sendDue();

        $row = OwnerSummary::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('No messages left', (string) $row->error);
        $this->assertSame([], $this->gateway->sent);
    }

    public function test_two_owners_two_hospitals_each_their_own(): void
    {
        $otherOwner = $this->user('hospital_admin', $this->other, ['email' => 'boss@other.test']);
        $this->optIn('email');
        $this->optIn('email', '07:30', $otherOwner);

        $this->assertSame(['sent' => 2, 'failed' => 0], $this->service()->sendDue());
        $rows = OwnerSummary::query()->withoutGlobalScopes()->pluck('hospital_id', 'user_id')->all();
        $this->assertSame([$this->owner->id => $this->h->id, $otherOwner->id => $this->other->id], $rows);
    }

    public function test_an_owner_who_left_is_not_sent_to(): void
    {
        $this->optIn('email');
        $this->owner->forceFill(['is_active' => false])->save();

        $this->assertSame(['sent' => 0, 'failed' => 0], $this->service()->sendDue());
    }

    // ── The settings card ───────────────────────────────────────────────

    public function test_the_owner_sees_and_saves_the_card_on_settings_messages(): void
    {
        $this->actingAs($this->owner);
        app(CurrentHospital::class)->set($this->h->id);

        Livewire::test(\App\Livewire\Settings\Messages::class)->assertSee('My daily summary');

        Livewire::test(\App\Livewire\Settings\OwnerSummary::class)
            ->assertSee('What it would say today')
            ->set('enabled', true)->set('channel', 'email')->set('at', '06:45')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertSame(['enabled' => true, 'channel' => 'email', 'at' => '06:45'], $this->service()->preferences($this->owner));
    }

    public function test_the_card_shows_a_refusal_in_place(): void
    {
        $owner = $this->user('hospital_admin', $this->h, ['phone' => null]);
        $this->actingAs($owner);
        app(CurrentHospital::class)->set($this->h->id);

        Livewire::test(\App\Livewire\Settings\OwnerSummary::class)
            ->set('enabled', true)->set('channel', 'sms')
            ->call('save')
            ->assertHasErrors('channel');
    }

    public function test_nobody_else_gets_the_card(): void
    {
        $accountant = $this->user('accountant', $this->h);
        $this->actingAs($accountant);
        app(CurrentHospital::class)->set($this->h->id);

        Livewire::test(\App\Livewire\Settings\OwnerSummary::class)->assertForbidden();
    }

    // ── The API ─────────────────────────────────────────────────────────

    public function test_the_api_reads_and_saves_the_owners_choice(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/owner-summary')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.channels.0.value', 'email')
            ->assertJsonStructure(['data' => ['enabled', 'channel', 'at', 'channels', 'preview', 'recent']]);

        $this->putJson('/api/v1/owner-summary', ['enabled' => true, 'channel' => 'sms', 'at' => '07:00'])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.channel', 'sms')->assertJsonPath('data.at', '07:00');

        $this->putJson('/api/v1/owner-summary', ['enabled' => true, 'channel' => 'pigeon', 'at' => '7'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_the_api_is_for_the_owner_only_and_shows_only_their_own(): void
    {
        $this->getJson('/api/v1/owner-summary')->assertUnauthorized();

        Sanctum::actingAs($this->user('doctor', $this->h));
        $this->getJson('/api/v1/owner-summary')->assertForbidden();

        $otherOwner = $this->user('hospital_admin', $this->other, ['email' => 'boss@other.test']);
        $this->optIn('email', '07:30', $otherOwner);
        $this->service()->sendDue();

        Sanctum::actingAs($this->owner);
        $this->assertSame([], $this->getJson('/api/v1/owner-summary')->assertOk()->json('data.recent'));
    }

    public function test_a_replayed_save_is_answered_the_same(): void
    {
        Sanctum::actingAs($this->owner);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['enabled' => true, 'channel' => 'email', 'at' => '07:15'];

        $first = $this->putJson('/api/v1/owner-summary', $body, $headers)->assertOk()->json();
        $second = $this->putJson('/api/v1/owner-summary', $body, $headers)->assertOk()->json();
        $this->assertSame($first, $second);
    }
}
