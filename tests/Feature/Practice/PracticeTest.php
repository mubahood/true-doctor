<?php

namespace Tests\Feature\Practice;

use App\Livewire\Onboarding\Steps\Practice as PracticeStep;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Room;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\Ward;
use App\Services\Finance\ChartOfAccounts;
use App\Services\PracticeService;
use App\Support\Navigation;
use App\Support\OnboardingStatus;
use App\Support\Practice\Practice;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A hospital's kind of practice decides what it is offered (docs/practice.md):
 * every suggestion, the one-click setup, its income accounts and its menu.
 */
class PracticeTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital(['currency' => 'UGX']);
        $this->actingAsRole('hospital_admin', $this->hospital);
    }

    private function choose(string $sector, array $packs = [], ?array $modules = null): Practice
    {
        return app(PracticeService::class)->choose($this->hospital, $sector, $packs, $modules);
    }

    public function test_every_sector_builds_a_template_with_unique_names(): void
    {
        foreach (Practice::sectors() as $key => $sector) {
            $template = (new Practice($sector))->template();

            foreach (['departments', 'services', 'rooms', 'labTests', 'radiologyStudies', 'stockItems', 'wards'] as $list) {
                $names = array_map(fn ($r) => mb_strtolower($r['name']), $template->{$list}());
                $this->assertSame(count($names), count(array_unique($names)), "{$key}: {$list} repeats a name");
            }

            $this->assertNotEmpty($template->services(), "{$key} offers nothing to charge for");
            $categories = array_map(fn ($c) => mb_strtolower($c['name']), $template->stockCategories());
            foreach ($template->stockItems() as $item) {
                $this->assertContains(mb_strtolower($item['category']), $categories, "{$key}: {$item['name']} has no category");
            }
        }
    }

    public function test_a_dental_practice_is_offered_dentistry_not_wards(): void
    {
        $this->choose('dental_clinic');

        $services = array_column(SampleCatalogue::services(), 'name');
        $this->assertContains('Root canal treatment', $services);
        $this->assertNotContains('Caesarean section', $services);
        $this->assertSame([], SampleCatalogue::wards());
        $this->assertFalse(Practice::current()->uses('inpatient'));
        // The practice's own reasons straight after the two every practice has.
        $this->assertSame(['New complaint', 'Follow-up visit', 'Toothache'], array_slice(SampleCatalogue::visitReasons(), 0, 3));
        $this->assertContains('Dental caries', SampleCatalogue::clinicalPhrases()['diagnosis']);
        $this->assertContains('Extraction', SampleCatalogue::orderTitles()['procedure']);
    }

    public function test_adding_a_specialty_adds_its_suggestions(): void
    {
        $this->choose('medical_clinic', ['dental']);

        $this->assertContains('Dental check-up', array_column(SampleCatalogue::services(), 'name'));
        $this->assertContains('General consultation', array_column(SampleCatalogue::services(), 'name'));
    }

    public function test_prices_read_as_prices_in_the_hospitals_currency(): void
    {
        $this->hospital->update(['settings' => ['billing' => ['currency_code' => 'KES']]]);
        $this->choose('medical_clinic');

        $consultation = collect(SampleCatalogue::services())->firstWhere('name', 'General consultation');
        $this->assertEqualsWithDelta(700.0, $consultation['price'], 0.01);   // 20,000 UGX ≈ KSh 700
    }

    public function test_a_hospital_that_has_not_chosen_gets_the_general_hospital_catalogue(): void
    {
        $this->assertFalse(Practice::current()->chosen);
        $this->assertSame('general_hospital', Practice::current()->sector->key);
        $this->assertContains('Caesarean section', array_column(SampleCatalogue::services(), 'name'));
        $this->assertContains('Tooth extraction', array_column(SampleCatalogue::services(), 'name'));
    }

    public function test_applying_sets_the_practice_up_once_with_income_accounts_and_opening_stock(): void
    {
        $practice = $this->choose('dental_clinic');
        $created = app(PracticeService::class)->apply($practice);

        $this->assertGreaterThan(20, $created['services']);
        $this->assertArrayNotHasKey('wards', $created);
        $this->assertTrue(Room::where('name', 'Dental Chair 1')->exists());
        $this->assertSame(0, Ward::count());

        $dental = app(ChartOfAccounts::class)->key('revenue.dental');
        $crown = Service::where('name', 'Crown (porcelain fused to metal)')->firstOrFail();
        $this->assertSame('Dental', $crown->category);
        $this->assertSame($dental->id, $crown->ledger_account_id);
        $this->assertTrue(LedgerAccount::where('name', 'Dental laboratory fees')->exists());

        // Opening stock is the hospital's own, not a debt to a supplier.
        $this->assertGreaterThan(0, StockItem::count());
        $this->assertSame('0.00', app(\App\Services\Finance\FinancialStatements::class)
            ->balances(now())[app(ChartOfAccounts::class)->key('payable')->id]);
        $this->assertTrue(JournalEntry::where('memo', 'like', 'Opening stock%')->exists());

        // A second run adds nothing and changes nothing.
        $crown->update(['price' => 1]);
        $again = app(PracticeService::class)->apply($practice);
        $this->assertSame(0, array_sum($again));
        $this->assertSame('1.00', (string) $crown->fresh()->price);
    }

    public function test_applying_respects_names_already_used_including_deleted_ones(): void
    {
        Department::create(['name' => 'Dental', 'code' => 'DEN', 'is_active' => true])->delete();
        Department::create(['name' => 'Somewhere else', 'code' => 'ORTH', 'is_active' => true]);

        $created = app(PracticeService::class)->apply($this->choose('dental_clinic'), ['departments']);

        $this->assertSame(1, $created['departments']);
        $this->assertNull(Department::where('name', 'Orthodontics')->value('code'));
    }

    public function test_choosing_a_practice_is_a_required_setup_step(): void
    {
        $status = app(OnboardingStatus::class);
        $this->assertFalse($status->practiceChosen($this->hospital));
        $this->assertSame('practice', $status->steps($this->hospital)->first()->key);

        $this->choose('eye_clinic');
        $this->assertTrue($status->practiceChosen($this->hospital->fresh()));
    }

    public function test_a_practice_without_inpatients_is_not_asked_about_wards_or_shown_them(): void
    {
        $this->choose('dental_clinic');
        $user = auth()->user()->fresh();

        $this->assertFalse(app(OnboardingStatus::class)->steps($this->hospital->fresh())->contains('key', 'wards'));

        $routes = collect(Navigation::for($user, false)['sections'])
            ->flatMap(fn ($s) => $s['entries'])
            ->flatMap(fn ($e) => $e['type'] === 'group' ? $e['items'] : [$e])
            ->pluck('route');

        $this->assertNotContains('admin.wards.index', $routes);
        $this->assertNotContains('admin.admissions.board', $routes);
        $this->assertContains('admin.visits.index', $routes);
        $this->assertContains('admin.finance.index', $routes);

        // Switched back on, it comes straight back.
        $this->choose('dental_clinic', [], ['pharmacy', 'inpatient']);
        $routes = collect(Navigation::for($user->fresh(), false)['sections'])
            ->flatMap(fn ($s) => $s['entries'])
            ->flatMap(fn ($e) => $e['type'] === 'group' ? $e['items'] : [$e])
            ->pluck('route');
        $this->assertContains('admin.wards.index', $routes);
    }

    public function test_the_step_saves_the_choice_and_sets_everything_up(): void
    {
        Livewire::test(PracticeStep::class)
            ->call('pickSector', 'eye_clinic')
            ->assertSet('modules', ['pharmacy', 'insurance', 'cards'])
            ->call('togglePack', 'ent')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('onboarding-updated')
            ->call('apply')
            ->assertDispatched('toast');

        $practice = Practice::of($this->hospital->fresh());
        $this->assertSame('eye_clinic', $practice->sector->key);
        $this->assertSame(['ent'], $practice->extraPacks);
        $this->assertTrue(Service::where('name', 'Refraction')->exists());
        $this->assertTrue(Service::where('name', 'Audiometry (hearing test)')->exists());
        $this->assertTrue(StockItem::where('name', 'Single-vision lenses')->exists());
    }

    public function test_only_someone_who_configures_the_hospital_can_choose(): void
    {
        $this->actingAsRole('doctor', $this->hospital);

        Livewire::test(PracticeStep::class)->assertForbidden();
    }

    public function test_the_settings_page_renders_the_same_form(): void
    {
        $this->get(route('admin.settings.practice'))->assertOk()->assertSee('Your practice')->assertSee('Dental clinic');
    }
}
