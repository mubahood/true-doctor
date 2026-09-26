<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeHospitalsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): array
    {
        $h = Hospital::factory()->create(['slug' => $slug]);
        app(CurrentHospital::class)->set($h->id);
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $u->createToken('app');
        $p = Patient::factory()->create(['hospital_id' => $h->id]);
        Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);

        return [$h, $u];
    }

    public function test_a_spam_hospital_goes_with_everything_it_owns_and_nothing_else(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        [$spam, $spammer] = $this->tenant('vam-perevod-spam');
        [$demo, $demoAdmin] = $this->tenant('general-hospital-a');

        $this->artisan('hospitals:purge', ['slugs' => ['vam-perevod-spam'], '--dry-run' => true])->assertSuccessful();
        $this->assertNotNull(Hospital::withoutGlobalScopes()->find($spam->id), 'a dry run changes nothing');

        $this->artisan('hospitals:purge', ['slugs' => ['vam-perevod-spam'], '--force' => true])->assertSuccessful();

        $this->assertNull(DB::table('hospitals')->where('id', $spam->id)->first());
        $this->assertSame(0, DB::table('users')->where('id', $spammer->id)->count());
        $this->assertSame(0, DB::table('patients')->where('hospital_id', $spam->id)->count());
        $this->assertSame(0, DB::table('visits')->where('hospital_id', $spam->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $spammer->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $spammer->id)->count());

        // The demonstration is exactly as it was.
        $this->assertNotNull(DB::table('hospitals')->where('id', $demo->id)->first());
        $this->assertSame(1, DB::table('patients')->where('hospital_id', $demo->id)->count());
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $demoAdmin->id)->count());
    }

    public function test_the_demonstration_and_a_paying_customer_are_never_removed(): void
    {
        [$demo] = $this->tenant('general-hospital-a');
        $this->artisan('hospitals:purge', ['slugs' => ['general-hospital-a'], '--force' => true])->assertFailed();
        $this->assertNotNull(DB::table('hospitals')->where('id', $demo->id)->first());

        [$customer] = $this->tenant('real-clinic');
        $sub = DB::table('subscriptions')->insertGetId(['hospital_id' => $customer->id, 'plan_id' => \App\Models\Plan::factory()->create()->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscription_payments')->insert(['subscription_id' => $sub, 'amount' => '10.00', 'method' => 'cash', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('hospitals:purge', ['slugs' => ['real-clinic'], '--force' => true])->assertFailed();
        $this->assertNotNull(DB::table('hospitals')->where('id', $customer->id)->first());

        // One bad slug in the list and nothing at all is removed.
        [$spam] = $this->tenant('spam-one');
        $this->artisan('hospitals:purge', ['slugs' => ['spam-one', 'no-such-hospital'], '--force' => true])->assertFailed();
        $this->assertNotNull(DB::table('hospitals')->where('id', $spam->id)->first());
    }
}
