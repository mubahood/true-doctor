<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\Plan;
use App\Models\StockItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Visit;
use App\Services\PracticeService;
use App\Support\Insights\InsightEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /api/v1/insights: ranked, permission- and practice-aware suggestions, cached 60s per user. */
class InsightsApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
        Cache::flush();
    }

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);
        Cache::flush();

        return $u;
    }

    /** @return list<string> */
    private function keys(string $query = ''): array
    {
        return collect($this->getJson('/api/v1/insights'.$query)->assertOk()->json('data.insights'))->pluck('key')->all();
    }

    private function item(string $key): ?array
    {
        return collect($this->getJson('/api/v1/insights')->assertOk()->json('data.insights'))->firstWhere('key', $key);
    }

    private function patient(?Hospital $h = null): Patient
    {
        return Patient::factory()->create(['hospital_id' => ($h ?? $this->h)->id]);
    }

    private function visitFor(?Hospital $h = null, array $extra = []): Visit
    {
        $h ??= $this->h;

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $this->patient($h)->id] + $extra);
    }

    // ── Contract ────────────────────────────────────────────────

    public function test_it_needs_a_token(): void
    {
        $this->getJson('/api/v1/insights')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_every_item_has_the_contract_shape_and_a_real_web_route(): void
    {
        $this->as('hospital_admin');
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);

        $items = $this->getJson('/api/v1/insights')->assertOk()->json('data.insights');
        $this->assertNotEmpty($items);
        foreach ($items as $i) {
            $this->assertEqualsCanonicalizing(['key', 'tone', 'title', 'detail', 'count', 'route', 'web_url', 'action_label'], array_keys($i));
            $this->assertContains($i['tone'], ['info', 'warn', 'danger', 'success']);
            $this->assertTrue(Route::has($i['route']), "{$i['route']} is not a web route");
        }
    }

    public function test_every_rule_names_routes_that_exist(): void
    {
        // Run each rule's route through the router, whether or not it fires today.
        foreach (InsightEngine::RULES as $rule) {
            $this->assertTrue(class_exists($rule));
            $src = file_get_contents((new \ReflectionClass($rule))->getFileName());
            preg_match_all("/'(admin\.[a-z\-\.]+)'/", $src, $m);
            foreach ($m[1] as $name) {
                $this->assertTrue(Route::has($name), "{$rule} points at {$name}");
            }
        }
    }

    public function test_a_quiet_hospital_is_told_so(): void
    {
        $this->as('pharmacist');

        $items = $this->getJson('/api/v1/insights')->assertOk()->json('data.insights');
        $this->assertSame(['all-clear'], array_column($items, 'key'));
        $this->assertSame('success', $items[0]['tone']);
    }

    public function test_limit_is_validated_and_applied(): void
    {
        $this->as('hospital_admin');
        // Several things at once.
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '5', 'expiry_date' => now()->subDay()]);

        $this->assertGreaterThan(1, count($this->keys()));
        $this->assertCount(1, $this->keys('?limit=1'));

        foreach (['0', '21', 'x'] as $bad) {
            $this->getJson('/api/v1/insights?limit='.$bad)
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('limit', 'errors');
        }
    }

    public function test_it_is_ranked_danger_first_and_capped_at_eight_by_default(): void
    {
        $this->as('hospital_admin');
        // A mixed bag: expired stock (danger), a doctor with no hours (warn), setup open (warn).
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '5', 'expiry_date' => now()->subDay()]);
        User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor']);

        $items = $this->getJson('/api/v1/insights')->json('data.insights');
        $this->assertLessThanOrEqual(8, count($items));
        $rank = ['danger' => 4, 'warn' => 3, 'info' => 2, 'success' => 1];
        $scores = array_map(fn ($i) => $rank[$i['tone']], $items);
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
        $this->assertSame('danger', $items[0]['tone']);
    }

    public function test_the_answer_is_cached_for_a_minute_per_user(): void
    {
        $this->as('pharmacist');
        $this->assertSame(['all-clear'], $this->keys());

        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);
        $this->assertSame(['all-clear'], $this->keys(), 'served from the cache');

        Cache::flush();
        $this->assertContains('stock-running-out', $this->keys());
    }

    // ── Roles ───────────────────────────────────────────────────

    public function test_each_role_sees_only_what_it_can_act_on(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);
        $inv = $this->invoice($this->h, '100.00', '0.00', 40);

        $pharmacist = $this->as('pharmacist');
        $keys = $this->keys();
        $this->assertContains('stock-running-out', $keys);
        $this->assertNotContains('invoices-overdue', $keys);
        $this->assertNotContains('setup-incomplete', $keys);

        $this->as('receptionist');
        $keys = $this->keys();
        $this->assertContains('invoices-overdue', $keys);
        $this->assertNotContains('stock-running-out', $keys);

        $this->as('lab_technician');
        $this->assertSame(['all-clear'], $this->keys());
        $this->assertNotNull($inv);
        $this->assertNotNull($pharmacist);
    }

    public function test_a_practice_that_does_not_run_a_pharmacy_is_not_told_about_stock(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);
        app(PracticeService::class)->choose($this->h, 'diagnostic_lab');

        $this->as('pharmacist');
        $this->assertSame(['all-clear'], $this->keys());

        // ...until the clinic turns the module on.
        app(PracticeService::class)->choose($this->h->fresh(), 'diagnostic_lab', [], ['laboratory', 'pharmacy']);
        app()->forgetInstance(\App\Support\HospitalSettings::class);
        Cache::flush();
        $this->assertContains('stock-running-out', $this->keys());
    }

    public function test_other_hospitals_data_never_leaks_in(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->other->id, 'current_quantity' => '0']);
        $this->invoice($this->other, '900.00', '0.00', 80);
        $this->visitFor($this->other, ['created_at' => now()->subDays(9)]);

        $this->as('hospital_admin');
        $keys = $this->keys();
        foreach (['stock-running-out', 'stock-expired', 'invoices-overdue', 'invoices-unpaid', 'visits-stuck-open'] as $k) {
            $this->assertNotContains($k, $keys);
        }
    }

    public function test_the_cache_is_per_user(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);
        $this->as('pharmacist');
        $this->assertContains('stock-running-out', $this->keys());

        // A different user in the same hospital without the permission gets their own answer.
        $u = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'lab_technician']);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);
        $this->assertSame(['all-clear'], $this->keys());
    }

    // ── Rules ───────────────────────────────────────────────────

    public function test_stock_rules(): void
    {
        $this->as('pharmacist');
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '5', 'reorder_level' => '1', 'expiry_date' => now()->subDays(2)]);
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '50', 'reorder_level' => '1', 'expiry_date' => now()->addDays(10)]);
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '3', 'reorder_level' => '10']);

        $expired = $this->item('stock-expired');
        $this->assertSame('danger', $expired['tone']);
        $this->assertSame(1, $expired['count']);
        $this->assertSame('admin.stock.alerts', $expired['route']);

        $out = $this->item('stock-running-out');
        $this->assertSame('danger', $out['tone']);
        $this->assertSame(2, $out['count']);

        $this->assertSame(1, $this->item('stock-expiring')['count']);
    }

    public function test_queue_rules(): void
    {
        $this->as('receptionist');
        $doctor = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor']);
        $mk = fn (array $a) => Appointment::factory()->create($a + [
            'hospital_id' => $this->h->id, 'patient_id' => $this->patient()->id, 'doctor_user_id' => $doctor->id,
        ]);

        // At midday: "two hours ago" must still be today, whenever the suite runs.
        $this->travelTo(now()->setTime(12, 0));
        $today = now()->format('Y-m-d');
        // Waiting 50 minutes, 25 minutes, and 5 minutes.
        foreach ([50, 25, 5] as $min) {
            $mk(['status' => 'checked_in', 'scheduled_at' => now()->subMinutes($min + 10), 'ends_at' => now()->addMinutes(20), 'checked_in_at' => now()->subMinutes($min)]);
        }
        // Expected an hour ago and never came; and one no-show.
        $mk(['status' => 'scheduled', 'scheduled_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30)]);
        $mk(['status' => 'no_show', 'scheduled_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        // Another hospital's long wait does not count.
        Appointment::factory()->create(['status' => 'checked_in', 'scheduled_at' => now()->subHours(3), 'ends_at' => now(), 'checked_in_at' => now()->subHours(2)]);

        $wait = $this->item('queue-long-waits');
        $this->assertSame('danger', $wait['tone']);
        $this->assertSame(2, $wait['count']);
        $this->assertSame('admin.appointments.queue', $wait['route']);
        $this->assertSame(1, $this->item('appointments-not-arrived')['count']);
        $this->assertSame(1, $this->item('no-shows-today')['count']);
        $this->assertNotNull($today);
    }

    public function test_receivables_rules_split_overdue_from_merely_unpaid(): void
    {
        $this->as('accountant');
        $this->invoice($this->h, '100.00', '0.00', 3);
        $this->assertNull($this->item('invoices-overdue'));
        $unpaid = $this->item('invoices-unpaid');
        $this->assertSame('warn', $unpaid['tone']);
        $this->assertSame(1, $unpaid['count']);

        $this->invoice($this->h, '250.00', '50.00', 45);
        Cache::flush();
        $overdue = $this->item('invoices-overdue');
        $this->assertSame('danger', $overdue['tone']);
        $this->assertStringContainsString('200', $overdue['title']);
        $this->assertNull($this->item('invoices-unpaid'), 'the overdue item speaks for the unpaid ones');
    }

    public function test_visits_stuck_open_and_a_doctor_sees_only_their_own(): void
    {
        $doctor = $this->as('doctor');
        $this->visitFor(null, ['created_at' => now()->subDays(5), 'doctor_user_id' => $doctor->id]);
        $this->visitFor(null, ['created_at' => now()->subDays(5)]); // somebody else's
        $this->visitFor(null, ['created_at' => now()->subDay()]);    // too recent

        $this->assertSame(1, $this->item('visits-stuck-open')['count']);

        $this->as('receptionist');
        $this->assertSame(2, $this->item('visits-stuck-open')['count']);
    }

    public function test_lab_and_radiology_waiting(): void
    {
        $visit = $this->visitFor();
        $row = fn (string $status, int $hoursAgo) => [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $this->h->id, 'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id, 'status' => $status,
            'created_at' => now()->subHours($hoursAgo), 'updated_at' => now(),
        ];
        DB::table('lab_orders')->insert([$row('ordered', 30), $row('processing', 2), $row('completed', 90)]);
        DB::table('radiology_orders')->insert([$row('ordered', 50), $row('reported', 90)]);

        $this->as('lab_technician');
        $this->assertSame(1, $this->item('lab-waiting')['count']);
        $this->assertNull($this->item('radiology-waiting'));

        $this->as('radiologist');
        $this->assertSame(1, $this->item('radiology-waiting')['count']);

        $this->as('hospital_admin');
        $keys = $this->keys();
        $this->assertContains('lab-waiting', $keys);
        $this->assertContains('radiology-waiting', $keys);
    }

    public function test_claims_and_insurer_float(): void
    {
        $this->as('accountant');
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $this->h->id]);
        $patient = $this->patient();
        $invoiceId = $this->invoice($this->h, '10.00', '10.00', 1);
        $claim = fn (string $status, ?int $submittedDaysAgo = null) => [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $this->h->id,
            'claim_no' => 'C-'.\Illuminate\Support\Str::random(6), 'patient_id' => $patient->id,
            'insurance_provider_id' => $provider->id, 'invoice_id' => $invoiceId, 'amount' => '10.00', 'status' => $status,
            'submitted_at' => $submittedDaysAgo === null ? null : now()->subDays($submittedDaysAgo),
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('insurance_claims')->insert([$claim('draft'), $claim('approved', 3), $claim('submitted', 20), $claim('submitted', 2), $claim('paid', 40)]);

        $c = $this->item('claims-awaiting-action');
        $this->assertSame(3, $c['count']);
        $this->assertSame('warn', $c['tone']);

        // Members owe 500; the insurer holds 200.
        DB::table('insurance_providers')->where('id', $provider->id)->update(['float_balance' => '200.00']);
        PatientCard::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'insurance_provider_id' => $provider->id, 'balance' => '-500.00']);
        Cache::flush();
        $short = $this->item('insurer-float-short');
        $this->assertSame(1, $short['count']);
        $this->assertStringContainsString('300', $short['detail']);

        $this->as('receptionist');
        $this->assertNotNull($this->item('claims-awaiting-action'));
        $this->assertNull($this->item('insurer-float-short'), 'needs insurance.manage');
    }

    public function test_books_catch_up_and_financial_year(): void
    {
        $this->as('accountant');
        $this->invoice($this->h, '100.00', '0.00', 1);

        $this->assertGreaterThan(0, $this->item('books-catch-up')['count']);
        $fy = $this->item('financial-year');
        $this->assertSame('warn', $fy['tone']);
        $this->assertSame('admin.financial-years.index', $fy['route']);

        DB::table('financial_years')->insert([
            'hospital_id' => $this->h->id, 'name' => 'FY now', 'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->addDays(10)->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::flush();
        $fy = $this->item('financial-year');
        $this->assertSame('info', $fy['tone']);
        $this->assertStringContainsString('10 days', $fy['title']);

        DB::table('financial_years')->update(['ends_on' => now()->addMonths(6)->toDateString()]);
        Cache::flush();
        $this->assertNull($this->item('financial-year'));

        $this->as('receptionist');
        $this->assertNull($this->item('books-catch-up'));
        $this->assertNull($this->item('financial-year'));
    }

    public function test_long_stay_admissions(): void
    {
        $this->as('nurse');
        $visit = $this->visitFor();
        $p = $visit->patient;
        $row = fn (int $days, string $status = 'admitted') => [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $this->h->id, 'patient_id' => $p->id, 'visit_id' => $visit->id,
            'status' => $status, 'admitted_at' => now()->subDays($days), 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('admissions')->insert([$row(20), $row(3), $row(30, 'discharged')]);

        $this->assertSame(1, $this->item('admissions-long-stay')['count']);
    }

    public function test_admin_sees_setup_gaps_and_doctors_without_hours(): void
    {
        $this->as('hospital_admin');
        $doc = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor', 'is_active' => true]);
        $covered = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor', 'is_active' => true]);
        DoctorSchedule::factory()->create(['hospital_id' => $this->h->id, 'user_id' => $covered->id]);
        // Another hospital's doctor without hours is not ours to count.
        User::factory()->create(['hospital_id' => $this->other->id, 'role' => 'doctor']);

        $setup = $this->item('setup-incomplete');
        $this->assertSame('warn', $setup['tone']);
        $this->assertSame('admin.settings.practice', $setup['route']);

        $d = $this->item('doctors-without-availability');
        $this->assertSame(1, $d['count']);
        $this->assertSame('admin.schedules.index', $d['route']);

        $this->as('receptionist');
        $this->assertNull($this->item('doctors-without-availability'));
        $this->assertNull($this->item('setup-incomplete'));
        $this->assertNotNull($doc);
    }

    public function test_trial_ending_is_saas_only_and_admin_only(): void
    {
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'hospital_id' => $this->h->id, 'plan_id' => $plan->id, 'status' => 'trialing',
            'starts_at' => now()->subDays(12), 'ends_at' => now()->addDays(2), 'trial_ends_at' => now()->addDays(2),
        ]);

        $this->as('hospital_admin');
        $t = $this->item('subscription-ending');
        $this->assertSame('danger', $t['tone']);
        $this->assertSame('admin.subscription.index', $t['route']);

        $this->as('accountant');
        $this->assertNull($this->item('subscription-ending'));

        // On a standalone installation the rule does not apply at all, and the licence one does.
        $admin = $this->as('hospital_admin');
        config(['edition.name' => 'standalone']);
        $ctx = new \App\Support\Insights\InsightContext($admin, $this->h, \App\Support\Practice\Practice::of($this->h), now());
        $this->assertFalse(app(\App\Support\Insights\Rules\TrialEnding::class)->applies($ctx));
        $this->assertTrue(app(\App\Support\Insights\Rules\LicenceUpdatesEnding::class)->applies($ctx));
        config(['edition.name' => 'saas']);
        $this->assertTrue(app(\App\Support\Insights\Rules\TrialEnding::class)->applies($ctx));
        $this->assertFalse(app(\App\Support\Insights\Rules\LicenceUpdatesEnding::class)->applies($ctx));
    }

    public function test_one_failing_rule_does_not_take_the_list_away(): void
    {
        $this->as('pharmacist');
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'current_quantity' => '0']);
        // Break one rule's table.
        $this->app->bind(\App\Support\Insights\Rules\StockExpired::class, fn () => new class implements \App\Support\Insights\InsightRule
        {
            public function key(): string
            {
                return 'broken';
            }

            public function applies(\App\Support\Insights\InsightContext $c): bool
            {
                return true;
            }

            public function evaluate(\App\Support\Insights\InsightContext $c): ?\App\Support\Insights\Insight
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->assertContains('stock-running-out', $this->keys());
    }

    private function invoice(Hospital $h, string $total, string $paid, int $daysAgo): int
    {
        $visit = $this->visitFor($h);
        $created = now()->subDays($daysAgo);

        return DB::table('invoices')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $h->id, 'invoice_no' => 'INV-'.\Illuminate\Support\Str::random(6),
            'visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'currency' => 'UGX',
            'subtotal' => $total, 'tax_total' => '0', 'discount' => '0', 'total' => $total, 'amount_paid' => $paid,
            'balance' => bcsub($total, $paid, 2), 'status' => bccomp($paid, '0', 2) > 0 ? 'partially_paid' : 'issued',
            'issued_at' => $created, 'created_at' => $created, 'updated_at' => $created,
        ]);
    }
}
