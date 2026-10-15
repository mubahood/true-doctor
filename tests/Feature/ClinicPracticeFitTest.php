<?php

namespace Tests\Feature;

use App\Livewire\Schedules\Index as Schedules;
use App\Livewire\Services\Index as PriceList;
use App\Livewire\Users\Index as Users;
use App\Livewire\Visits\Panels\Charges;
use App\Models\Hospital;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use App\Support\Navigation;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the first clinic on its own server needed of the product: prices
 * that are ranges, a dentist who also runs the clinic, and a menu in a dental
 * practice's own words.
 */
class ClinicPracticeFitTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config()->set('onboarding.gate_enabled', false);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
        $this->actingAs($this->admin);
    }

    // ── Prices that are ranges ───────────────────────────────────────────

    public function test_a_fixed_price_is_charged_whatever_is_asked_and_a_range_only_within_itself(): void
    {
        $fixed = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '30000.00']);
        $range = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '50000.00', 'max_price' => '80000.00']);

        $this->assertFalse($fixed->hasRange());
        $this->assertSame('30000.00', $fixed->chargePrice('99999'));
        $this->assertTrue($range->hasRange());
        $this->assertSame('50000.00', $range->chargePrice(null));
        $this->assertSame('65000.00', $range->chargePrice('65000'));
        $this->assertSame('80000.00', $range->chargePrice('80000'));

        $this->expectException(\RuntimeException::class);
        $range->chargePrice('80000.01');
    }

    public function test_the_counter_charges_a_ranged_service_where_it_says_within_the_range(): void
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id]);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Difficult extraction', 'price' => '50000.00', 'max_price' => '80000.00']);

        $panel = Livewire::test(Charges::class, ['visitId' => $visit->id])
            ->call('picked', 'service_id', $service->id)
            ->assertSet('unit_price', '50000.00')
            ->set('unit_price', '90000')
            ->call('addLine')
            ->assertHasErrors('unit_price');
        $this->assertSame(0, OrderItem::count());

        $panel->set('unit_price', '70000')->call('addLine')->assertHasNoErrors();

        $line = OrderItem::firstOrFail();
        $this->assertSame('70000.00', (string) $line->unit_price);
        $this->assertSame('Difficult extraction', $line->name);
    }

    public function test_a_fixed_price_service_shows_no_price_box_and_charges_the_list_price(): void
    {
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id]);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '30000.00']);

        Livewire::test(Charges::class, ['visitId' => $visit->id])
            ->call('picked', 'service_id', $service->id)
            ->assertSet('unit_price', null)
            ->call('addLine')
            ->assertHasNoErrors();

        $this->assertSame('30000.00', (string) OrderItem::firstOrFail()->unit_price);
    }

    public function test_the_price_list_keeps_a_range_and_refuses_a_top_below_the_start(): void
    {
        Livewire::test(PriceList::class)
            ->call('create')
            ->set('name', 'Scaling and polishing')
            ->set('price', '100000')
            ->set('max_price', '90000')
            ->call('save')
            ->assertHasErrors('max_price')
            ->set('max_price', '130000')
            ->call('save')
            ->assertHasNoErrors();

        $service = Service::where('name', 'Scaling and polishing')->firstOrFail();
        $this->assertSame('130000.00', (string) $service->max_price);
        $this->assertStringContainsString('–', $service->priceLabel());
    }

    // ── A dentist who also runs the clinic ───────────────────────────────

    public function test_an_administrator_who_sees_patients_is_bookable_and_on_the_roster(): void
    {
        $owner = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin', 'name' => 'Dr. Owner', 'sees_patients' => true]);
        User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin', 'name' => 'Office Admin']);
        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr. Doctor']);

        $clinicians = User::currentHospital()->clinicians()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$owner->id, $doctor->id], $clinicians);
        $this->assertTrue($owner->seesPatients());

        $this->assertSame(2, Livewire::test(Schedules::class)->instance()->facts()['doctors']);
    }

    public function test_the_user_form_marks_somebody_as_also_seeing_patients(): void
    {
        Mail::fake();
        $user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);

        Livewire::test(Users::class)
            ->call('edit', $user->id)
            ->set('sees_patients', true)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue($user->fresh()->sees_patients);

        // A doctor sees patients by being one; the flag is never stored for them.
        Livewire::test(Users::class)
            ->call('edit', $doctor->id)
            ->set('sees_patients', true)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertFalse($doctor->fresh()->sees_patients);
    }

    // ── The menu in the practice's own words ─────────────────────────────

    public function test_a_dental_clinic_keeps_a_drugs_inventory_not_a_pharmacy(): void
    {
        $this->choose('dental_clinic', ['pharmacy']);
        $this->assertContains('Drugs inventory', $this->menuLabels());
        $this->assertNotContains('Pharmacy', $this->menuLabels());

        $this->choose('general_hospital', null);
        $this->assertContains('Pharmacy', $this->menuLabels());
    }

    /** @param list<string>|null $modules */
    private function choose(string $sector, ?array $modules): void
    {
        $settings = $this->hospital->fresh()->settings ?? [];
        $settings['practice'] = ['sector' => $sector, 'packs' => [], 'modules' => $modules, 'chosen_at' => now()->toIso8601String()];
        $this->hospital->update(['settings' => $settings]);
        $this->admin->unsetRelation('hospital');
        app()->forgetInstance(\App\Support\HospitalSettings::class);
    }

    /** @return list<string> */
    private function menuLabels(): array
    {
        $labels = [];
        foreach (Navigation::for($this->admin->fresh(), false)['sections'] as $section) {
            foreach ($section['entries'] ?? [] as $entry) {
                $labels[] = (string) ($entry['label'] ?? '');
            }
        }

        return $labels;
    }
}
