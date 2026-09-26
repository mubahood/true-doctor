<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Livewire\Super\Hospitals\Index;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The super admin's list of hospitals: who, since when, on what, in use or
 * not, and how to reach them.
 */
class SuperHospitalsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->actingAs(User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]));
    }

    private function hospital(string $name, ?SubscriptionStatus $status, array $sub = [], ?Carbon $joined = null): Hospital
    {
        $hospital = Hospital::factory()->create(['name' => $name, 'created_at' => $joined ?? Carbon::now()]);

        User::factory()->create([
            'hospital_id' => $hospital->id, 'role' => 'hospital_admin',
            'name' => "Owner of {$name}", 'email' => \Illuminate\Support\Str::slug($name).'@owner.test', 'phone' => '+256700'.$hospital->id,
            'last_active_at' => Carbon::now()->subHours(3),
        ]);

        if ($status !== null) {
            Subscription::withoutGlobalScopes()->create($sub + [
                'hospital_id' => $hospital->id,
                'plan_id' => Plan::where('slug', 'starter')->value('id'),
                'starts_at' => Carbon::now()->subDays(3),
                'ends_at' => Carbon::now()->addDays(11),
                'trial_ends_at' => $status === SubscriptionStatus::Trialing ? Carbon::now()->addDays(11) : null,
                'status' => $status,
            ]);
        }

        return $hospital;
    }

    public function test_the_page_shows_owner_plan_dates_and_usage(): void
    {
        $this->hospital('Mulago Clinic', SubscriptionStatus::Trialing, joined: Carbon::parse('2026-09-01 10:00'));

        $this->get('/super/hospitals')
            ->assertOk()
            ->assertSee('Mulago Clinic')
            ->assertSee('mulago-clinic@owner.test')
            ->assertSee('Owner of Mulago Clinic')
            ->assertSee('Starter')
            ->assertSee('Trial ends')
            ->assertSee('1 Sep 2026')
            ->assertSee('3 hours ago');
    }

    public function test_the_newest_hospital_comes_first(): void
    {
        $this->hospital('Older', null, joined: Carbon::now()->subDays(20));
        $this->hospital('Newer', null, joined: Carbon::now()->subDay());

        $this->get('/super/hospitals')->assertSeeInOrder(['Newer', 'Older']);
    }

    public function test_each_subscription_filter_finds_the_right_hospitals(): void
    {
        $this->hospital('Paying One', SubscriptionStatus::Active);
        $this->hospital('Trial Far', SubscriptionStatus::Trialing);
        $this->hospital('Trial Soon', SubscriptionStatus::Trialing, ['trial_ends_at' => Carbon::now()->addDays(2)]);
        $this->hospital('Lapsed One', SubscriptionStatus::Expired);
        $this->hospital('Never Started', null);

        $expect = [
            'active' => ['Paying One'],
            'trialing' => ['Trial Far', 'Trial Soon'],
            'ending' => ['Trial Soon'],
            'expired' => ['Lapsed One'],
            'none' => ['Never Started'],
        ];

        foreach ($expect as $filter => $names) {
            $shown = Livewire::test(Index::class)->set('plan', $filter)->viewData('rows')->pluck('name')->sort()->values()->all();
            $this->assertSame(collect($names)->sort()->values()->all(), $shown, "filter {$filter}");
        }

        $stats = Livewire::test(Index::class)->instance()->stats;
        $this->assertSame(5, $stats['total']);
        $this->assertSame(1, $stats['paying']);
        $this->assertSame(2, $stats['trialing']);
        $this->assertSame(1, $stats['ending']);
        $this->assertSame(1, $stats['lapsed']);
        $this->assertSame(1, $stats['no_sub']);
    }

    /** The latest subscription decides — an old trial must not count once they pay. */
    public function test_only_the_latest_subscription_counts(): void
    {
        $hospital = $this->hospital('Converted', SubscriptionStatus::Trialing, ['starts_at' => Carbon::now()->subDays(20)]);
        Subscription::withoutGlobalScopes()->create([
            'hospital_id' => $hospital->id, 'plan_id' => Plan::where('slug', 'professional')->value('id'),
            'starts_at' => Carbon::now()->subDay(), 'ends_at' => Carbon::now()->addMonth(), 'status' => SubscriptionStatus::Active,
        ]);

        $this->assertSame(['Converted'], Livewire::test(Index::class)->set('plan', 'active')->viewData('rows')->pluck('name')->all());
        $this->assertSame([], Livewire::test(Index::class)->set('plan', 'trialing')->viewData('rows')->pluck('name')->all());
    }

    public function test_search_finds_a_hospital_by_its_owners_email(): void
    {
        $this->hospital('Hidden Name Clinic', null);
        $this->hospital('Other Clinic', null);

        $shown = Livewire::test(Index::class)->set('search', 'hidden-name-clinic@owner')->viewData('rows')->pluck('name')->all();

        $this->assertSame(['Hidden Name Clinic'], $shown);
    }

    public function test_nonsense_filters_are_ignored_not_errors(): void
    {
        $this->hospital('Any Clinic', null);

        Livewire::test(Index::class)->set('plan', 'wat')->set('state', 'nope')->assertOk()->assertSet('plan', '')->assertSee('Any Clinic');
        Livewire::test(Index::class)->call('sortBy', 'password')->assertOk();
        Livewire::test(Index::class)->call('sortBy', 'patients_count')->assertOk();
        Livewire::test(Index::class)->call('sortBy', 'last_active_at')->assertOk();
    }

    public function test_the_quick_view_has_the_staff_and_payments(): void
    {
        $hospital = $this->hospital('Peek Clinic', SubscriptionStatus::Active);
        SubscriptionPayment::create([
            'subscription_id' => Subscription::withoutGlobalScopes()->where('hospital_id', $hospital->id)->value('id'),
            'amount' => 350000, 'method' => 'pesapal', 'reference' => 'PK-REF-1', 'paid_at' => Carbon::now(),
        ]);

        Livewire::test(Index::class)
            ->call('peek', $hospital->id)
            ->assertSet('showPeek', true)
            ->assertSee('Owner of Peek Clinic')
            ->assertSee('PK-REF-1')
            ->assertSee('350,000')
            ->assertSee('Subscription history');
    }

    public function test_a_hospital_admin_still_cannot_see_the_list(): void
    {
        $hospital = Hospital::factory()->create();
        $this->actingAs(User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']))
            ->get('/super/hospitals')->assertForbidden();
    }
}
