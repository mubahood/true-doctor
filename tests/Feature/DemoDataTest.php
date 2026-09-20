<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\PlanLimit;
use Database\Seeders\DemoMoney;
use Database\Seeders\DemoPeople;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demonstration hospital.
 *
 * SmokeRoutesTest already walks every screen against this data, which proves
 * it renders. What is held here is that it is data somebody could be SHOWN:
 * priced in the currency the hospital actually uses, on a plan that does not
 * refuse the next thing a demonstrator clicks, and broad enough that the
 * charts have more than one bar.
 *
 * The three-month workload lives in DemoPopulationSeeder and is deliberately
 * not seeded here — it takes the better part of a minute, and this suite runs
 * on every commit. Its parts are tested where they are cheap to test.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    // ── The money ────────────────────────────────────────────────────────

    public function test_the_hospital_every_test_account_lands_in_uses_dollars(): void
    {
        $this->assertSame('USD', $this->hospital->currency);
        $this->assertSame('USD', $this->hospital->settings['billing']['currency_code'] ?? null);
        $this->assertSame('$', $this->hospital->settings['billing']['currency_symbol'] ?? null);
        $this->assertSame(2, $this->hospital->settings['billing']['decimals'] ?? null);
    }

    /**
     * A second tenant on a different currency, because a demo with one money
     * format proves nothing about the money code.
     */
    public function test_the_second_tenant_is_on_a_different_currency(): void
    {
        $b = Hospital::where('slug', 'city-clinic-b')->firstOrFail();

        $this->assertNotSame($this->hospital->currency, $b->currency);
    }

    /**
     * The gap this closed: prices were written in shillings and the hospital
     * then read them as dollars, so a consultation cost twenty thousand of
     * them and a bed was thirty thousand a night.
     */
    public function test_nothing_is_priced_in_the_wrong_currency(): void
    {
        // Nothing an outpatient clinic sells costs four figures in dollars.
        $dearest = Service::where('is_active', true)->max('price');
        $this->assertLessThan(1000, (float) $dearest, "a demo service is priced at {$dearest}");

        $dearestTest = LabTest::where('is_active', true)->max('price');
        $this->assertLessThan(1000, (float) $dearestTest, "a demo lab test is priced at {$dearestTest}");

        $dearestBed = Bed::max('daily_charge');
        $this->assertLessThan(1000, (float) $dearestBed, "a demo bed is {$dearestBed} a night");
    }

    public function test_the_conversion_itself(): void
    {
        // Written in shillings once; read in whatever the tenant uses.
        $this->assertSame('20000', DemoMoney::in('UGX', 20000));
        $this->assertSame('20.00', DemoMoney::in('USD', 20000));
        $this->assertSame('0.00', DemoMoney::in('USD', 0));
        // No thousands separator: this is going into a decimal column.
        $this->assertSame('800.00', DemoMoney::in('USD', 800000));
        $this->assertStringNotContainsString(',', DemoMoney::in('USD', 1500000));
        // An unknown currency is left alone rather than guessed at.
        $this->assertSame('20000.00', DemoMoney::in('XOF', 20000));
    }

    // ── The plan ─────────────────────────────────────────────────────────

    /**
     * The gap this closed: the demo tenant was subscribed to the CHEAPEST
     * plan, which allows five staff. The seeder creates ten. Every demo
     * hospital was therefore already over its own limit, and the next staff
     * account, patient or bed created through the panel was refused by
     * PlanLimit for a reason nobody demonstrating could act on.
     */
    public function test_the_demo_tenant_is_on_a_plan_that_does_not_refuse_the_next_click(): void
    {
        $limits = app(PlanLimit::class);

        foreach (['staff', 'patients', 'beds'] as $resource) {
            $limits->assertCanCreate($resource);
        }

        $this->assertNull($limits->limitFor('staff'), 'the demo plan caps staff accounts');
        $this->assertNull($limits->limitFor('patients'), 'the demo plan caps patients');
        $this->assertNull($limits->limitFor('beds'), 'the demo plan caps beds');
    }

    public function test_every_role_has_an_account_and_can_reach_the_panel(): void
    {
        $roles = ['hospital_admin', 'doctor', 'nurse', 'receptionist', 'pharmacist',
            'lab_technician', 'radiologist', 'accountant', 'records_officer'];

        foreach ($roles as $role) {
            $user = User::where('hospital_id', $this->hospital->id)->where('role', $role)->first();

            $this->assertNotNull($user, "no demo account for {$role}");
            $this->assertTrue($user->is_active, "the demo {$role} is deactivated");
            $this->assertFalse((bool) $user->password_change_required, "the demo {$role} is held at a password reset");
            $this->assertTrue($user->canAccessAdmin(), "the demo {$role} cannot reach the panel");
        }
    }

    // ── The roster the population seeder draws on ────────────────────────

    public function test_the_roster_is_big_enough_and_spread_across_every_band(): void
    {
        $roster = DemoPeople::roster();

        $this->assertGreaterThan(50, count($roster), 'the demo roster is smaller than asked for');

        $bands = ['0-17' => 0, '18-39' => 0, '40-64' => 0, '65+' => 0];
        $sexes = [];

        foreach ($roster as [$first, $last, $sex, $age]) {
            $sexes[$sex] = true;
            $band = $age < 18 ? '0-17' : ($age < 40 ? '18-39' : ($age < 65 ? '40-64' : '65+'));
            $bands[$band]++;
        }

        // Every column the demographics report buckets by has somebody in it.
        foreach ($bands as $band => $count) {
            $this->assertGreaterThan(0, $count, "no demo patient is in the {$band} band");
        }

        $this->assertArrayHasKey('male', $sexes);
        $this->assertArrayHasKey('female', $sexes);
        $this->assertArrayHasKey('other', $sexes);
    }

    public function test_no_two_people_on_the_roster_share_a_name(): void
    {
        // The population seeder is idempotent BY NAME — a duplicate would
        // make the second one un-seedable and the count quietly short.
        $names = array_map(
            fn (array $person) => $person[0].' '.$person[1],
            DemoPeople::roster(),
        );

        $this->assertSame(count($names), count(array_unique($names)));
    }

    public function test_the_conditions_and_allergies_point_at_real_people(): void
    {
        $size = count(DemoPeople::roster());

        foreach (array_keys(DemoPeople::allergies()) as $index) {
            $this->assertLessThan($size, $index, "an allergy is recorded against roster position {$index}, which does not exist");
        }

        foreach (array_keys(DemoPeople::conditions()) as $index) {
            $this->assertLessThan($size, $index, "a condition is recorded against roster position {$index}, which does not exist");
        }
    }

    // ── What DemoSeeder itself leaves behind ─────────────────────────────

    public function test_the_demo_hospital_has_patients_with_generated_numbers(): void
    {
        $patient = Patient::first();

        $this->assertNotNull($patient);
        $this->assertMatchesRegularExpression('/^PT-\d{4}-\d{6}[A-Z0-9]$/', (string) $patient->patient_no);
    }
}
