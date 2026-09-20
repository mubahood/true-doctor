<?php

namespace Tests\Feature\Admissions;

use App\Enums\AdmissionStatus;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\WardService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A stay is billed a night at a time.
 *
 * It used to be one line raised at discharge — "Bed charge — 4 night(s)" —
 * priced at whatever the bed cost on the day the patient left. Now each night
 * goes on the stay's own order as it completes, at the rate in force then.
 *
 * The money must not have changed for a stay whose rate never moved: the
 * totals below are the same figures the single line produced. What has
 * changed is that the bill exists before discharge, reads as the nights it
 * actually was, and cannot be rewritten backwards by a price change.
 */
class NightlyBedChargeTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    private AdmissionService $admissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();

        $this->admissions = app(AdmissionService::class);
    }

    /** @return array{0:Admission,1:Bed,2:Visit} */
    private function stay(string $rate = '40000.00', ?Carbon $admittedAt = null): array
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'daily_charge' => $rate,
        ]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);

        $admission = $this->admissions->admit($patient, $bed, [
            'visit_id' => $visit->id,
            'admitted_at' => $admittedAt ?? Carbon::now(),
        ], $this->nurse->id);

        return [$admission, $bed, $visit];
    }

    private function billed(Visit $visit): string
    {
        return $visit->orderItems()->get()
            ->reduce(fn (string $sum, $i) => bcadd($sum, (string) $i->line_total, 2), '0.00');
    }

    // ── The stay is on a visit ───────────────────────────────────────────

    public function test_a_stay_is_always_an_order_on_a_visit(): void
    {
        [$admission, , $visit] = $this->stay();

        $this->assertSame($visit->id, $admission->visit_id);
        $this->assertDatabaseHas('orders', [
            'visit_id' => $visit->id,
            'subject_type' => $admission->getMorphClass(),
            'subject_id' => $admission->id,
        ]);
    }

    public function test_a_stay_with_no_visit_named_opens_one_rather_than_floating_free(): void
    {
        // The admit form lets the visit be left blank. It must not mean "no
        // visit" — a stay that is not on a visit is work nobody can bill.
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $admission = $this->admissions->admit($patient, $bed, [], $this->nurse->id);

        $this->assertNotNull($admission->visit_id);
        $this->assertSame($patient->id, $admission->visit->patient_id);
    }

    // ── A night at a time ────────────────────────────────────────────────

    public function test_the_admission_day_itself_bills_nothing(): void
    {
        // A night is complete when the next day starts. Somebody admitted
        // this afternoon has not finished one.
        [$admission, , $visit] = $this->stay();

        $this->assertSame(0, $this->admissions->accrueNightlyCharges($admission, Carbon::now()));
        $this->assertSame('0.00', $this->billed($visit->fresh()));
    }

    public function test_each_completed_night_becomes_its_own_item(): void
    {
        [$admission, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(3));

        $added = $this->admissions->accrueNightlyCharges($admission, Carbon::now());

        $this->assertSame(3, $added);
        $this->assertSame(3, $visit->orderItems()->count());
        $this->assertSame('120000.00', $this->billed($visit));
        $this->assertSame('120000.00', (string) $admission->fresh()->bed_charge_total);
    }

    public function test_each_item_says_which_night_and_which_bed(): void
    {
        [$admission, $bed] = $this->stay('40000.00', Carbon::now()->subDay());
        $this->admissions->accrueNightlyCharges($admission, Carbon::now());

        $name = $admission->fresh()->visit->orderItems()->first()->name;

        // The prefix the single line used to carry, kept so anything reading
        // for it still finds it, plus the two facts it could not give.
        $this->assertStringStartsWith('Bed charge —', $name);
        $this->assertStringContainsString($bed->name, $name);
        $this->assertStringContainsString('night of', $name);
    }

    // ── Running it more than once ────────────────────────────────────────

    public function test_running_it_twice_in_a_day_bills_nothing_the_second_time(): void
    {
        // The property the whole design rests on. A retry, a manual run, an
        // overlapping schedule — none of them may bill a night twice.
        [$admission, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(2));

        $this->admissions->accrueNightlyCharges($admission, Carbon::now());
        $second = $this->admissions->accrueNightlyCharges($admission, Carbon::now());

        $this->assertSame(0, $second);
        $this->assertSame(2, $visit->orderItems()->count());
        $this->assertSame('80000.00', $this->billed($visit));
    }

    public function test_it_catches_up_after_days_of_not_running(): void
    {
        // The scheduler was off for a week. Every missed night still lands,
        // once each.
        [$admission, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(7));

        $added = $this->admissions->accrueNightlyCharges($admission, Carbon::now());

        $this->assertSame(7, $added);
        $this->assertSame(7, $visit->orderItems()->count());
        $this->assertSame('280000.00', $this->billed($visit));
    }

    public function test_it_leaves_a_discharged_stay_alone(): void
    {
        [$admission, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(2));
        $this->admissions->discharge($admission->fresh(), AdmissionStatus::Discharged, null, $this->nurse->id);

        $before = $this->billed($visit);
        $this->assertSame(0, $this->admissions->accrueNightlyCharges($admission->fresh(), Carbon::now()));
        $this->assertSame($before, $this->billed($visit));
    }

    // ── A price change no longer reaches backwards ───────────────────────

    /**
     * The behaviour this change exists for.
     *
     * Before: the rate was read at discharge and multiplied by the whole
     * stay, so putting the ward rate up on the last day repriced every night
     * of it. Now a night keeps the price it was billed at.
     */
    public function test_a_rate_change_prices_only_the_nights_that_follow_it(): void
    {
        [$admission, $bed, $visit] = $this->stay('40000.00', Carbon::now()->subDays(4));

        // Two nights at the old rate.
        $this->admissions->accrueNightlyCharges($admission, Carbon::now()->subDays(2));
        $this->assertSame('80000.00', $this->billed($visit));

        // The ward is repriced.
        app(WardService::class)->applyNightlyChargeToBeds($bed->ward, '60000.00');

        // Two more nights, at the new rate.
        $this->admissions->accrueNightlyCharges($admission, Carbon::now());

        // 2 × 40,000 + 2 × 60,000 — NOT 4 × 60,000.
        $this->assertSame('200000.00', $this->billed($visit));
        $this->assertSame('200000.00', (string) $admission->fresh()->bed_charge_total);
    }

    // ── Discharge ────────────────────────────────────────────────────────

    public function test_discharge_bills_only_what_is_outstanding(): void
    {
        [$admission, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(3));

        $this->admissions->accrueNightlyCharges($admission, Carbon::now());
        $this->assertSame(3, $visit->orderItems()->count());

        $this->admissions->discharge($admission->fresh(), AdmissionStatus::Discharged, null, $this->nurse->id);

        // Nothing added twice: the nights were already there.
        $this->assertSame(3, $visit->orderItems()->count());
        $this->assertSame('120000.00', (string) $admission->fresh()->bed_charge_total);
    }

    public function test_a_stay_that_was_never_accrued_is_billed_in_full_on_the_way_out(): void
    {
        // The scheduler has never run. The bill must still be right, and must
        // still read one line per night.
        [$admission, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(3));

        $this->admissions->discharge($admission->fresh(), AdmissionStatus::Discharged, null, $this->nurse->id);

        $this->assertSame(3, $visit->orderItems()->count());
        $this->assertSame('120000.00', (string) $admission->fresh()->bed_charge_total);
    }

    public function test_a_same_day_stay_is_charged_one_night(): void
    {
        // Unchanged from before: `max(1, …)`. Somebody who occupied a bed for
        // an afternoon is charged for it.
        [$admission, , $visit] = $this->stay('40000.00', Carbon::now());

        $this->admissions->discharge($admission->fresh(), AdmissionStatus::Discharged, null, $this->nurse->id);

        $this->assertSame(1, $visit->orderItems()->count());
        $this->assertSame('40000.00', (string) $admission->fresh()->bed_charge_total);
    }

    public function test_a_free_bed_consumes_its_nights_without_billing_for_them(): void
    {
        [$admission, , $visit] = $this->stay('0.00', Carbon::now()->subDays(2));

        $this->admissions->accrueNightlyCharges($admission, Carbon::now());

        // The counter moved — those nights are accounted for and will not be
        // billed later — but an item worth nothing is noise on a bill.
        $this->assertSame(2, $admission->fresh()->nights_billed);
        $this->assertSame(0, $visit->orderItems()->count());
        $this->assertSame('0.00', (string) $admission->fresh()->bed_charge_total);
    }

    // ── The command ──────────────────────────────────────────────────────

    public function test_the_command_bills_every_open_stay(): void
    {
        [, , $visitA] = $this->stay('40000.00', Carbon::now()->subDays(2));
        [, , $visitB] = $this->stay('50000.00', Carbon::now()->subDay());

        $this->artisan('admissions:accrue-bed-charges')->assertSuccessful();

        $this->assertSame('80000.00', $this->billed($visitA));
        $this->assertSame('50000.00', $this->billed($visitB));
    }

    public function test_the_command_bills_each_hospitals_stay_into_its_own_books(): void
    {
        // A scheduled command belongs to no tenant, so it has to resolve one
        // per stay. Without that the order items would be written with no
        // hospital at all — a bed charge on nobody's books.
        [, , $mine] = $this->stay('40000.00', Carbon::now()->subDay());

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $ward = Ward::factory()->create(['hospital_id' => $other->id]);
        $bed = Bed::factory()->create(['hospital_id' => $other->id, 'ward_id' => $ward->id, 'daily_charge' => '10000.00']);
        $patient = Patient::factory()->create(['hospital_id' => $other->id]);
        $theirVisit = Visit::factory()->create(['hospital_id' => $other->id, 'patient_id' => $patient->id]);
        $this->admissions->admit($patient, $bed, [
            'visit_id' => $theirVisit->id,
            'admitted_at' => Carbon::now()->subDay(),
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->artisan('admissions:accrue-bed-charges')->assertSuccessful();

        $this->assertSame('40000.00', $this->billed($mine));

        app(CurrentHospital::class)->set($other->id);
        $this->assertSame('10000.00', $this->billed($theirVisit));
        $this->assertSame(
            0,
            \App\Models\OrderItem::withoutGlobalScopes()->whereNull('hospital_id')->count(),
            'A bed charge was written with no hospital.',
        );
    }

    public function test_the_command_changes_nothing_on_a_dry_run(): void
    {
        [, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(2));

        $this->artisan('admissions:accrue-bed-charges --dry-run')->assertSuccessful();

        $this->assertSame('0.00', $this->billed($visit));
    }

    public function test_the_command_can_be_told_what_day_it_is(): void
    {
        [, , $visit] = $this->stay('40000.00', Carbon::now()->subDays(5));

        // Catching up to three days ago bills three nights, not five.
        $this->artisan('admissions:accrue-bed-charges --date='.Carbon::now()->subDays(2)->toDateString())
            ->assertSuccessful();

        $this->assertSame('120000.00', $this->billed($visit));
    }
}
