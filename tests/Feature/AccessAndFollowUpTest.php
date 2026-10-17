<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\DentalTreatmentStatus;
use App\Enums\MessageStatus;
use App\Enums\Weekday;
use App\Livewire\Appointments\Recalls;
use App\Livewire\Settings\Access;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Visits\Panels\Review;
use App\Models\Appointment;
use App\Models\AuditEntry;
use App\Models\DentalTreatmentItem;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientMessage;
use App\Models\RoleAbility;
use App\Models\Service;
use App\Models\User;
use App\Models\UserAbility;
use App\Models\Visit;
use App\Services\AccessService;
use App\Services\FollowUpService;
use App\Services\Messaging\ConsentService;
use App\Services\Messaging\MessagingSettings;
use App\Services\StaffService;
use App\Services\Sync\PullService;
use App\Support\Access\Abilities;
use App\Support\Access\PermissionResolver;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A dental clinic's review, items C and D: the tightened default access,
 * Settings > Staff and access with per-person extras, and follow-up (book a
 * review from the visit; the Recalls and reviews due list).
 */
class AccessAndFollowUpTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->h = $this->hospital();
        $this->withHospital($this->h);
    }

    private function staff(string $role, ?Hospital $h = null, array $attrs = []): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role] + $attrs);
        $u->syncSpatieRole();

        return $u;
    }

    private function visit(?User $doctor = null, array $attrs = []): Visit
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);

        return Visit::factory()->create([
            'hospital_id' => $this->h->id,
            'patient_id' => $patient->id,
            'doctor_user_id' => $doctor?->id,
            'reason' => 'Toothache',
            'complaints' => 'Pain lower left for three days',
            'diagnosis' => 'Grossly carious 35',
        ] + $attrs);
    }

    private function fresh(User $u): User
    {
        app(PermissionResolver::class)->flush();

        return $u->fresh() ?? $u;
    }

    // ── Item C, part 1: tightened defaults ───────────────────────────────

    public function test_the_desk_and_the_cashier_do_not_read_the_clinical_record_and_a_doctor_does_not_see_the_money(): void
    {
        $this->assertFalse($this->staff('receptionist')->can('visits.clinical'));
        $this->assertFalse($this->staff('accountant')->can('visits.clinical'));
        $this->assertTrue($this->staff('nurse')->can('visits.clinical'));
        $this->assertFalse($this->staff('nurse')->can('visits.diagnose'));

        $doctor = $this->staff('doctor');
        $this->assertTrue($doctor->can('visits.clinical'));
        $this->assertTrue($doctor->can('billing.manage'));
        $this->assertFalse($doctor->can('billing.view'));
        $this->assertFalse($doctor->can('billing.discount'));
        $this->assertTrue($this->staff('receptionist')->can('billing.discount'));
    }

    public function test_the_visit_page_shows_the_desk_the_reason_and_says_the_notes_are_closed(): void
    {
        $doctor = $this->staff('doctor');
        $visit = $this->visit($doctor);

        $this->actingAs($this->staff('receptionist'))
            ->get(route('admin.visits.show', $visit))
            ->assertOk()
            ->assertSee('You do not have access to this. Ask your hospital admin.')
            ->assertDontSee('Grossly carious 35');

        Livewire::withoutLazyLoading()->actingAs($this->staff('receptionist'))
            ->test(\App\Livewire\Visits\Panels\Clinical::class, ['visitId' => $visit->id])
            ->assertForbidden();

        $this->actingAs($doctor)->get(route('admin.visits.report', $visit))->assertOk();
        $this->actingAs($this->staff('accountant'))->get(route('admin.visits.report', $visit))->assertForbidden();
    }

    public function test_a_doctor_sees_the_bill_of_their_own_patient_only(): void
    {
        $doctor = $this->staff('doctor');
        $mine = $this->visit($doctor);
        $theirs = $this->visit($this->staff('doctor'));

        $this->assertTrue($doctor->can('viewBill', $mine));
        $this->assertFalse($doctor->can('viewBill', $theirs));
        $this->assertTrue($this->staff('receptionist')->can('viewBill', $theirs));

        Livewire::withoutLazyLoading()->actingAs($doctor)->test(\App\Livewire\Visits\Panels\Charges::class, ['visitId' => $theirs->id])->assertForbidden();
        Livewire::withoutLazyLoading()->actingAs($doctor)->test(\App\Livewire\Visits\Panels\Charges::class, ['visitId' => $mine->id])->assertOk();
    }

    public function test_the_api_omits_the_clinical_record_for_the_desk(): void
    {
        $doctor = $this->staff('doctor');
        $visit = $this->visit($doctor);

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/visits/'.$visit->uuid)->assertOk()
            ->assertJsonPath('data.reason', 'Toothache')
            ->assertJsonMissingPath('data.diagnosis')
            ->assertJsonMissingPath('data.complaints');
        $summary = $this->getJson(route('api.patients.summary', $visit->patient))->assertOk()->json('data');
        $this->assertNotContains('clinical', $summary['sections']);
        $this->assertArrayNotHasKey('diagnosis', $summary['visits'][0]);
        $this->assertArrayNotHasKey('diagnoses', $summary);
        $this->getJson('/api/v1/visits/'.$visit->uuid.'/diagnoses')->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this. Ask your hospital admin.');

        Sanctum::actingAs($doctor);
        $this->getJson('/api/v1/visits/'.$visit->uuid)->assertOk()->assertJsonPath('data.diagnosis', 'Grossly carious 35');
    }

    public function test_the_sync_pull_carries_the_narrative_only_to_someone_who_may_read_it(): void
    {
        $doctor = $this->staff('doctor');
        $visit = $this->visit($doctor);
        $shape = function (User $u) use ($visit): array {
            $streams = (new \ReflectionMethod(PullService::class, 'streams'))->invoke(app(PullService::class), $u);

            return $streams['visits']['shape']($visit->fresh(['patient', 'doctor']));
        };

        $this->assertSame('Grossly carious 35', $shape($doctor)['diagnosis']);
        $this->assertArrayNotHasKey('diagnosis', $shape($this->staff('receptionist')));

        app(AccessService::class)->setForRole($this->staff('hospital_admin'), 'doctor', 'see_clinical', false);
        $this->assertArrayNotHasKey('diagnosis', $shape($this->fresh($doctor)));
    }

    public function test_the_dental_chart_is_closed_to_the_desk_on_the_api(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);
        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/patients/'.$patient->uuid.'/dental')->assertForbidden();
    }

    // ── Item C, part 2: Staff and access ────────────────────────────────

    public function test_the_admin_changes_a_role_and_it_applies_everywhere_and_is_audited(): void
    {
        $admin = $this->staff('hospital_admin');
        $desk = $this->staff('receptionist');
        $this->assertTrue($desk->can('billing.discount'));

        Livewire::actingAs($admin)->test(Access::class)
            ->assertOk()
            ->assertSee('Staff and access')
            ->call('toggle', 'receptionist', 'give_discounts');

        $this->assertDatabaseHas('role_abilities', ['hospital_id' => $this->h->id, 'role' => 'receptionist', 'ability' => 'give_discounts', 'allowed' => false]);
        $this->assertFalse($this->fresh($desk)->can('billing.discount'));
        $this->assertNotContains('billing.discount', $this->fresh($desk)->getAllPermissions()->pluck('name')->all());
        $this->assertTrue(AuditEntry::query()->where('subject_type', (new RoleAbility)->getMorphClass())->exists());

        // The app reads the same answer.
        Sanctum::actingAs($this->fresh($desk));
        $this->assertNotContains('billing.discount', $this->getJson('/api/v1/meta')->json('data.capabilities.permissions'));

        // An update re-applies the default matrix; the clinic's choice stays.
        $this->seed(RbacSeeder::class);
        $this->assertFalse($this->fresh($desk)->can('billing.discount'));

        // Ticking it back follows the standard again: no row left.
        Livewire::actingAs($admin)->test(Access::class)->call('toggle', 'receptionist', 'give_discounts');
        $this->assertSame(0, RoleAbility::query()->count());
        $this->assertTrue($this->fresh($desk)->can('billing.discount'));
    }

    public function test_abilities_that_need_the_notes_move_with_them(): void
    {
        $admin = $this->staff('hospital_admin');
        $doctor = $this->staff('doctor');

        $moved = app(AccessService::class)->setForRole($admin, 'doctor', 'see_clinical', false);
        $this->assertEqualsCanonicalizing(['see_clinical', 'record_vitals', 'diagnose_prescribe'], $moved);
        $this->assertFalse($this->fresh($doctor)->can('visits.diagnose'));

        $receptionist = $this->staff('receptionist');
        app(AccessService::class)->setForRole($admin, 'receptionist', 'record_vitals', true);
        $this->assertTrue($this->fresh($receptionist)->can('visits.vitals'));
        $this->assertTrue($this->fresh($receptionist)->can('visits.clinical'));
    }

    public function test_the_admin_role_cannot_be_changed_and_only_an_admin_changes_anything(): void
    {
        $admin = $this->staff('hospital_admin');

        $this->expectException(\DomainException::class);
        try {
            app(AccessService::class)->setForRole($this->staff('accountant'), 'receptionist', 'give_discounts', false);
        } catch (\DomainException) {
            app(AccessService::class)->setForRole($admin, 'hospital_admin', 'see_money', false);
        }
    }

    public function test_the_page_is_read_only_for_others_and_closed_to_roles_without_settings(): void
    {
        $this->actingAs($this->staff('receptionist'))->get(route('admin.settings.access'))->assertForbidden()
            ->assertSee('You do not have access to this');

        $doctor = $this->staff('doctor');
        UserAbility::query()->create(['user_id' => $doctor->id, 'ability' => 'manage_staff']);
        Livewire::actingAs($this->fresh($doctor))->test(Access::class)->assertOk()
            ->assertSee('Only a hospital admin can change these.')
            ->call('toggle', 'receptionist', 'give_discounts')->assertForbidden();
    }

    public function test_one_clinics_choices_never_reach_another(): void
    {
        $other = $this->hospital();
        $theirDesk = $this->staff('receptionist', $other);

        app(AccessService::class)->setForRole($this->staff('hospital_admin'), 'receptionist', 'give_discounts', false);

        $this->assertTrue($this->fresh($theirDesk)->can('billing.discount'));
        $this->withHospital($other);
        $this->assertSame([], RoleAbility::query()->get()->all());
    }

    public function test_an_extra_ability_for_one_person_from_their_account(): void
    {
        $admin = $this->staff('hospital_admin');
        $nurse = $this->staff('nurse', null, ['name' => 'Senior Nurse', 'email' => 'senior@example.test']);
        $this->assertFalse($nurse->can('billing.manage'));

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('edit', $nurse->id)
            ->assertSee('Extra abilities for this person')
            ->set('extras', ['take_payments'])
            ->call('save')
            ->assertHasNoErrors();

        $nurse = $this->fresh($nurse);
        $this->assertTrue($nurse->can('billing.manage'));
        $this->assertTrue($nurse->can('billing.view'));
        $this->assertSame(['take_payments'], app(AccessService::class)->personal($nurse));
        $this->assertTrue(AuditEntry::query()->where('subject_type', (new UserAbility)->getMorphClass())->exists());

        // Taken off again.
        Livewire::actingAs($admin)->test(UsersIndex::class)->call('edit', $nurse->id)->set('extras', [])->call('save');
        $this->assertFalse($this->fresh($nurse)->can('billing.manage'));
    }

    public function test_the_last_admin_cannot_be_removed_switched_off_or_demoted(): void
    {
        $admin = $this->staff('hospital_admin');
        $staff = app(StaffService::class);

        foreach ([fn () => $staff->setActive($admin, false), fn () => $staff->delete($admin), fn () => $staff->update($admin, ['role' => 'doctor'])] as $attempt) {
            try {
                $attempt();
                $this->fail('The last admin was locked out.');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('last hospital admin', $e->getMessage());
            }
        }

        $this->staff('hospital_admin');
        $staff->setActive($admin, false);
        $this->assertFalse($admin->fresh()->is_active);
    }

    public function test_somebody_given_staff_management_cannot_make_an_admin(): void
    {
        $accountant = $this->staff('accountant');
        UserAbility::query()->create(['user_id' => $accountant->id, 'ability' => 'manage_staff']);

        $this->assertNotContains('hospital_admin', app(StaffService::class)->assignableRoles($this->fresh($accountant)));
        $this->assertContains('hospital_admin', app(StaffService::class)->assignableRoles($this->staff('hospital_admin')));
    }

    public function test_the_abilities_never_share_a_permission_and_every_permission_exists(): void
    {
        $seen = [];
        foreach (array_keys(Abilities::ALL) as $key) {
            foreach (Abilities::permissions($key) as $p) {
                $this->assertArrayNotHasKey($p, $seen, "{$p} is in two abilities");
                $this->assertContains($p, RbacSeeder::PERMISSIONS);
                $seen[$p] = $key;
            }
        }
    }

    // ── Item D: follow-up ──────────────────────────────────────────────

    private function scheduleFor(User $doctor, Carbon $day): void
    {
        DoctorSchedule::query()->create([
            'hospital_id' => $this->h->id, 'user_id' => $doctor->id, 'weekday' => Weekday::from($day->dayOfWeek),
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 15, 'is_active' => true,
        ]);
    }

    public function test_the_visit_suggests_a_review_from_what_was_done(): void
    {
        $doctor = $this->staff('doctor');
        $visit = $this->visit($doctor, ['created_at' => Carbon::now()]);
        $this->assertSame(14, app(FollowUpService::class)->suggestion($visit)['days']);

        $service = Service::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Simple extraction']);
        $item = new DentalTreatmentItem(['uuid' => (string) Str::uuid(), 'patient_id' => $visit->patient_id, 'visit_id' => $visit->id, 'service_id' => $service->id, 'tooth' => 35, 'price' => '50000.00', 'status' => DentalTreatmentStatus::Done]);
        $item->forceFill(['hospital_id' => $this->h->id, 'done_visit_id' => $visit->id, 'done_at' => now()])->save();

        $s = app(FollowUpService::class)->suggestion($visit);
        $this->assertSame(7, $s['days']);
        $this->assertSame(Carbon::today()->addDays(7)->toDateString(), $s['date']);
    }

    public function test_a_review_is_booked_from_the_visit_and_lands_in_the_diary(): void
    {
        $doctor = $this->staff('doctor');
        $visit = $this->visit($doctor, ['created_at' => Carbon::now()]);
        $day = Carbon::today()->addDays(14);
        $this->scheduleFor($doctor, $day);

        Livewire::actingAs($this->staff('receptionist'))
            ->test(Review::class, ['visitId' => $visit->id])
            ->assertSet('date', $day->toDateString())
            ->assertSee('Book a review')
            ->set('time', '09:30')
            ->call('book')
            ->assertHasNoErrors();

        $appointment = Appointment::query()->sole();
        $this->assertSame($visit->id, $appointment->origin_visit_id);
        $this->assertSame($visit->patient_id, $appointment->patient_id);
        $this->assertSame($day->toDateString().' 09:30', $appointment->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('Review', $appointment->reason);

        Livewire::actingAs($this->staff('pharmacist'))->test(Review::class, ['visitId' => $visit->id])->assertForbidden();
    }

    public function test_the_review_api_books_and_refuses_a_taken_time(): void
    {
        $doctor = $this->staff('doctor');
        $visit = $this->visit($doctor, ['created_at' => Carbon::now()]);
        $day = Carbon::today()->addDays(3);
        $this->scheduleFor($doctor, $day);

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/visits/'.$visit->uuid.'/review')->assertOk()->assertJsonPath('data.suggestion.days', 14);
        $this->postJson('/api/v1/visits/'.$visit->uuid.'/review', ['date' => $day->toDateString(), 'time' => '10:00'])
            ->assertCreated()->assertJsonPath('data.reason', 'Review');
        // The time is now taken: another patient's review cannot have it.
        $other = $this->visit($doctor, ['created_at' => Carbon::now()]);
        $this->postJson('/api/v1/visits/'.$other->uuid.'/review', ['date' => $day->toDateString(), 'time' => '10:00'])
            ->assertStatus(422);
    }

    private function review(Visit $visit, Carbon $at, AppointmentStatus $status = AppointmentStatus::Scheduled): Appointment
    {
        $a = new Appointment([
            'uuid' => (string) Str::uuid(), 'patient_id' => $visit->patient_id, 'origin_visit_id' => $visit->id,
            'doctor_user_id' => $visit->doctor_user_id, 'scheduled_at' => $at, 'ends_at' => $at->copy()->addMinutes(15),
            'duration_minutes' => 15, 'source' => 'walk_in', 'status' => $status, 'reason' => 'Review',
        ]);
        $a->forceFill(['hospital_id' => $visit->hospital_id])->save();

        return $a;
    }

    public function test_the_recalls_list_has_this_weeks_reviews_missed_ones_and_check_ups_due(): void
    {
        $doctor = $this->staff('doctor');
        $soon = $this->review($this->visit($doctor, ['created_at' => now()->subDays(20)]), Carbon::today()->addDays(2)->setTime(10, 0));
        $missed = $this->review($this->visit($doctor, ['created_at' => now()->subDays(20)]), Carbon::today()->subDays(4)->setTime(10, 0), AppointmentStatus::NoShow);
        $cameBack = $this->visit($doctor, ['created_at' => now()->subDays(20)]);
        $this->review($cameBack, Carbon::today()->subDays(6)->setTime(10, 0));
        Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $cameBack->patient_id, 'created_at' => now()->subDays(5)]);

        $due = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Recall', 'next_recall_on' => Carbon::today()->subDays(20)->toDateString()]);

        // Another clinic's patient never shows.
        $other = $this->hospital();
        Patient::factory()->create(['hospital_id' => $other->id, 'first_name' => 'Foreign', 'next_recall_on' => Carbon::today()->toDateString()]);

        $counts = app(FollowUpService::class)->counts();
        $this->assertSame(['week' => 1, 'missed' => 1, 'recalls' => 1], $counts);

        $desk = $this->staff('receptionist');
        Livewire::actingAs($desk)->test(Recalls::class)->assertOk()->assertSee($soon->patient->full_name)->assertSee('Call');
        Livewire::actingAs($desk)->test(Recalls::class)->set('show', 'missed')->assertSee($missed->patient->full_name)->assertSee('Did not come');
        Livewire::actingAs($desk)->test(Recalls::class)->set('show', 'recalls')->assertSee($due->full_name)->assertSee('20 days overdue')->assertDontSee('Foreign');

        $this->actingAs($desk)->get(route('admin.appointments.recalls'))->assertOk()->assertSee('Recalls and reviews due');
        $this->actingAs($this->staff('pharmacist'))->get(route('admin.appointments.recalls'))->assertForbidden();
    }

    public function test_a_text_goes_once_and_only_with_consent(): void
    {
        MessagingSettings::save($this->h->fresh(), ['sms' => true, 'events' => [], 'quiet_start' => '23:59', 'quiet_end' => '00:00']);
        app(\App\Services\Messaging\MessageCredits::class)->post($this->h->id, 'topup', 50, 'TEST');
        $agreed = Patient::factory()->create(['hospital_id' => $this->h->id, 'phone_1' => '0772 123 456', 'next_recall_on' => Carbon::today()->toDateString()]);
        app(ConsentService::class)->record($agreed, ['sms.care' => true], 'desk');
        $refused = Patient::factory()->create(['hospital_id' => $this->h->id, 'phone_1' => '0772 654 321', 'next_recall_on' => Carbon::today()->toDateString()]);

        $desk = $this->staff('receptionist');
        Livewire::actingAs($desk)->test(Recalls::class)->set('show', 'recalls')
            ->call('text', 'recall', $agreed->id)
            ->call('text', 'recall', $agreed->id)
            ->call('text', 'recall', $refused->id);

        $this->assertSame(1, PatientMessage::query()->where('patient_id', $agreed->id)->where('event', 'follow_up_due')->count());
        $this->assertSame(MessageStatus::Skipped, PatientMessage::query()->where('patient_id', $refused->id)->sole()->status);

        // Another clinic's patient is not found, and a nurse cannot send.
        $foreign = Patient::factory()->create(['hospital_id' => $this->hospital()->id]);
        try {
            Livewire::actingAs($desk)->test(Recalls::class)->call('text', 'recall', $foreign->id);
            $this->fail('Another clinic\'s patient was found.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, PatientMessage::query()->where('patient_id', $foreign->id)->count());
        }
        Livewire::actingAs($this->staff('nurse'))->test(Recalls::class)->call('text', 'recall', $agreed->id)->assertForbidden();

        Sanctum::actingAs($desk);
        $this->getJson('/api/v1/follow-ups?show=recalls')->assertOk()->assertJsonPath('meta.counts.recalls', 2);
        $this->postJson('/api/v1/follow-ups/text', ['kind' => 'recall', 'id' => $agreed->id])->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('message', 'A text about this was already sent to '.$agreed->full_name.'.');
        $this->assertSame(1, PatientMessage::query()->where('patient_id', $agreed->id)->count());
    }

    // ── Regressions from the review ──────────────────────────────────────

    public function test_staff_management_never_reaches_an_admin_account(): void
    {
        $admin = $this->staff('hospital_admin');
        $accountant = $this->staff('accountant');
        UserAbility::query()->create(['user_id' => $accountant->id, 'ability' => 'manage_staff']);
        $accountant = $this->fresh($accountant);

        $this->assertTrue($accountant->can('manage-users'));
        $this->assertFalse($accountant->can('update', $admin));
        $this->assertFalse($accountant->can('delete', $admin));
        $this->assertTrue($accountant->can('update', $this->staff('nurse')));

        Sanctum::actingAs($accountant);
        $this->postJson('/api/v1/admin/users/'.$admin->id.'/reset-password', ['password' => 'N3w-pass-word!x', 'password_confirmation' => 'N3w-pass-word!x'])
            ->assertForbidden();
        $this->postJson('/api/v1/admin/users/'.$admin->id.'/deactivate')->assertForbidden();
        $this->assertTrue((bool) $admin->fresh()->is_active);

        // The setup wizard's staff step offers the same roles as the staff page.
        $this->actingAs($accountant);
        $this->assertArrayNotHasKey('hospital_admin', (new \App\Livewire\Onboarding\Steps\Staff)->roles());
    }

    public function test_a_prescription_or_quote_link_needs_the_clinical_record(): void
    {
        $desk = $this->staff('receptionist');
        $this->assertFalse(\App\Enums\SharedDocumentType::Prescription->allows($desk));
        $this->assertFalse(\App\Enums\SharedDocumentType::DentalQuote->allows($desk));
        $this->assertTrue(\App\Enums\SharedDocumentType::Prescription->allows($this->staff('doctor')));
    }

    public function test_vitals_are_for_the_clinical_team_and_the_desk_not_the_cashier(): void
    {
        $visit = $this->visit($this->staff('doctor'));

        $this->assertTrue($this->staff('receptionist')->can('viewVitals', $visit));
        $accountant = $this->staff('accountant');
        $this->assertFalse($accountant->can('viewVitals', $visit));

        Livewire::withoutLazyLoading()->actingAs($accountant)
            ->test(\App\Livewire\Visits\Panels\Vitals::class, ['visitId' => $visit->id])->assertForbidden();
    }

    public function test_coding_diagnoses_needs_the_clinical_record_too(): void
    {
        $records = $this->staff('records_officer');
        $this->assertTrue($records->can('diagnoses.code'));

        app(AccessService::class)->setForRole($this->staff('hospital_admin'), 'records_officer', 'see_clinical', false);
        $this->assertFalse($this->fresh($records)->can('diagnoses.code'));
    }

    public function test_a_partly_held_ability_can_be_switched_off(): void
    {
        $admin = $this->staff('hospital_admin');
        $desk = $this->staff('receptionist');
        $this->assertTrue($desk->can('billing.view'));
        $this->assertTrue(app(AccessService::class)->matrix()['receptionist']['see_money']['partial']);

        Livewire::actingAs($admin)->test(Access::class)->call('toggle', 'receptionist', 'see_money');

        $this->assertFalse($this->fresh($desk)->can('billing.view'));
        $this->assertFalse(app(AccessService::class)->matrix()['receptionist']['see_money']['on']);
    }

    public function test_low_stock_goes_to_whoever_the_clinic_lets_manage_stock(): void
    {
        $nurse = $this->staff('nurse');
        UserAbility::query()->create(['user_id' => $nurse->id, 'ability' => 'manage_stock']);
        app(PermissionResolver::class)->flush();
        $pharmacist = $this->staff('pharmacist');
        app(AccessService::class)->setForRole($this->staff('hospital_admin'), 'pharmacist', 'manage_stock', false);

        \Illuminate\Support\Facades\Notification::fake();
        $item = \App\Models\StockItem::factory()->create(['hospital_id' => $this->h->id]);
        app(\App\Listeners\NotifyUser::class)->handleStockBelowReorder(new \App\Events\StockBelowReorder($item));

        \Illuminate\Support\Facades\Notification::assertSentTo($nurse, \App\Notifications\LowStockAlert::class);
        \Illuminate\Support\Facades\Notification::assertNotSentTo($pharmacist, \App\Notifications\LowStockAlert::class);
    }

    public function test_booking_the_same_review_twice_is_one_booking(): void
    {
        $doctor = $this->staff('doctor');
        $visit = $this->visit($doctor, ['created_at' => Carbon::now()]);
        $day = Carbon::today()->addDays(3);
        $this->scheduleFor($doctor, $day);

        Sanctum::actingAs($this->staff('receptionist'));
        $first = $this->postJson('/api/v1/visits/'.$visit->uuid.'/review', ['date' => $day->toDateString(), 'time' => '11:00'])->assertCreated();
        $this->postJson('/api/v1/visits/'.$visit->uuid.'/review', ['date' => $day->toDateString(), 'time' => '11:00'])
            ->assertOk()->assertJsonPath('data.uuid', $first->json('data.uuid'));
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_a_recall_text_only_when_a_check_up_is_due(): void
    {
        $notDue = Patient::factory()->create(['hospital_id' => $this->h->id, 'next_recall_on' => Carbon::today()->addMonths(5)->toDateString()]);
        Sanctum::actingAs($this->staff('receptionist'));
        $this->postJson('/api/v1/follow-ups/text', ['kind' => 'recall', 'id' => $notDue->id])->assertStatus(422);
        $this->assertSame(0, PatientMessage::query()->count());
    }
}
