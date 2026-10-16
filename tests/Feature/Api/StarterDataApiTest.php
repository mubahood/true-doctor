<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Service;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Ward;
use App\Services\PracticeService;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Starter data over the API (the catalogue pages' import modals) and the onboarding hints that point at it. */
class StarterDataApiTest extends TestCase
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
    }

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    private function kinds(): array
    {
        return collect($this->getJson('/api/v1/admin/starter-data')->assertOk()->json('data.kinds'))->keyBy('kind')->all();
    }

    public function test_listing_describes_each_kind_for_the_practice(): void
    {
        $this->as('hospital_admin');

        $kinds = $this->kinds();
        $this->assertSame(['departments', 'services', 'wards', 'lab_tests', 'radiology_studies', 'stock_categories', 'stock_items'], array_keys($kinds));

        $d = $kinds['departments'];
        $this->assertSame(count(SampleCatalogue::departments()), $d['total']);
        $this->assertSame(0, $d['imported']);
        $this->assertSame($d['total'], $d['remaining']);
        $this->assertFalse($d['already_imported']);
        $this->assertTrue($d['can_import']);
        $this->assertSame('admin.departments.index', $d['app_route']);
    }

    public function test_a_practice_only_sees_the_kinds_it_uses(): void
    {
        app(PracticeService::class)->choose($this->h, 'diagnostic_lab');
        $this->as('hospital_admin');

        $keys = array_keys($this->kinds());
        $this->assertContains('lab_tests', $keys);
        $this->assertNotContains('wards', $keys);
        $this->assertNotContains('stock_items', $keys);

        $this->postJson('/api/v1/admin/starter-data/wards')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_import_creates_the_rows_the_web_modal_would_and_is_idempotent(): void
    {
        $this->as('hospital_admin');
        $expected = count(SampleCatalogue::departments());

        $this->postJson('/api/v1/admin/starter-data/departments')
            ->assertOk()->assertJsonPath('data.imported', $expected)->assertJsonPath('data.already_imported', true)->assertJsonPath('data.remaining', 0);
        $this->assertSame($expected, Department::count());

        // Again: nothing added.
        $this->postJson('/api/v1/admin/starter-data/departments')
            ->assertOk()->assertJsonPath('data.imported', 0)->assertJsonPath('message', 'Nothing left to import.');
        $this->assertSame($expected, Department::count());
    }

    public function test_an_existing_row_of_the_same_name_is_left_alone_and_counted_as_imported(): void
    {
        $this->as('hospital_admin');
        $first = SampleCatalogue::services()[0];
        Service::factory()->create(['name' => $first['name'], 'price' => 1, 'is_active' => true, 'hospital_id' => $this->h->id]);

        $before = $this->kinds()['services'];
        $this->assertSame(1, $before['imported']);

        $this->postJson('/api/v1/admin/starter-data/services')->assertOk();
        $this->assertSame(1, Service::where('name', $first['name'])->count());
        $this->assertSame('1.00', number_format((float) Service::where('name', $first['name'])->value('price'), 2, '.', ''));
    }

    public function test_names_choose_a_subset(): void
    {
        $this->as('hospital_admin');
        $name = SampleCatalogue::labTests()[0]['name'];

        $this->postJson('/api/v1/admin/starter-data/lab_tests', ['names' => [$name, 'Not in the catalogue']])
            ->assertOk()->assertJsonPath('data.imported', 1);
        $this->assertSame([$name], LabTest::pluck('name')->all());
    }

    public function test_stock_items_arrive_with_their_categories_and_opening_stock_through_the_ledger(): void
    {
        $this->as('hospital_admin');

        $this->postJson('/api/v1/admin/starter-data/stock_items')->assertOk();

        $this->assertGreaterThan(0, StockItem::count());
        $this->assertGreaterThan(0, StockCategory::count());
        $this->assertGreaterThan(0, StockItem::where('current_quantity', '>', 0)->count());
        $this->assertGreaterThan(0, \App\Models\StockMovement::count(), 'opening stock goes through the ledger');
    }

    public function test_wards_carry_their_starter_rate(): void
    {
        $this->as('hospital_admin');
        $this->postJson('/api/v1/admin/starter-data/wards')->assertOk();

        $this->assertGreaterThan(0, Ward::count());
        $this->assertGreaterThan(0, (float) Ward::max('default_daily_charge'));
    }

    public function test_validation_envelope_on_bad_names(): void
    {
        $this->as('hospital_admin');

        $this->postJson('/api/v1/admin/starter-data/departments', ['names' => 'Cardiology'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('names', 'errors');
    }

    public function test_unknown_kind_is_404(): void
    {
        $this->as('hospital_admin');
        $this->postJson('/api/v1/admin/starter-data/rooms')->assertNotFound();
    }

    public function test_the_import_needs_the_same_permission_as_the_web_page(): void
    {
        foreach (['nurse', 'receptionist', 'pharmacist', 'lab_technician'] as $role) {
            $this->as($role);
            $this->postJson('/api/v1/admin/starter-data/services')->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
        $this->assertSame(0, Service::count());

        // Reading the list is open to anyone with access-admin, with can_import false.
        $this->as('nurse');
        $this->assertFalse($this->kinds()['services']['can_import']);

        // An accountant manages the price list but not the departments.
        $this->as('accountant');
        $this->postJson('/api/v1/admin/starter-data/services')->assertOk();
        $this->postJson('/api/v1/admin/starter-data/departments')->assertForbidden();

        // A pharmacist may fill the pharmacy catalogue, not the price list.
        $this->as('pharmacist');
        $this->postJson('/api/v1/admin/starter-data/stock_categories')->assertOk();
    }

    public function test_it_is_isolated_per_hospital(): void
    {
        $this->as('hospital_admin', $this->other);
        $this->postJson('/api/v1/admin/starter-data/departments')->assertOk();

        $this->as('hospital_admin');
        $this->assertSame(0, $this->kinds()['departments']['imported']);
        $this->assertSame(0, Department::count());
        $this->assertGreaterThan(0, Department::withoutGlobalScopes()->where('hospital_id', $this->other->id)->count());
    }

    public function test_it_needs_a_token_and_admin_access(): void
    {
        $this->getJson('/api/v1/admin/starter-data')->assertUnauthorized();
        $this->postJson('/api/v1/admin/starter-data/services')->assertUnauthorized();
    }

    public function test_onboarding_steps_carry_the_app_route_and_starter_kind(): void
    {
        $this->as('hospital_admin');

        $steps = collect($this->getJson('/api/v1/admin/onboarding')->assertOk()->json('data.steps'))->keyBy('key');

        $this->assertSame('admin.settings.practice', $steps['practice']['app_route']);
        $this->assertNull($steps['practice']['starter_kind']);
        $this->assertSame('admin.settings.hospital', $steps['profile']['app_route']);
        $this->assertSame('admin.settings.billing', $steps['billing']['app_route']);
        $this->assertSame('admin.departments.index', $steps['departments']['app_route']);
        $this->assertSame('departments', $steps['departments']['starter_kind']);
        $this->assertSame('services', $steps['services']['starter_kind']);
        $this->assertSame('admin.users.index', $steps['staff']['app_route']);
        $this->assertNull($steps['staff']['starter_kind']);
        $this->assertSame('wards', $steps['wards']['starter_kind']);
        $this->assertSame('lab_tests', $steps['catalogues']['starter_kind']);

        // Every route named is a real web route, and every kind is a real starter kind.
        foreach ($steps as $s) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($s['app_route']), $s['key']);
            if ($s['starter_kind'] !== null) {
                $this->assertArrayHasKey($s['starter_kind'], \App\Support\StarterData::KINDS);
            }
        }
        // web_route stays as it was.
        $this->assertStringEndsWith('/admin/services', $steps['services']['web_route']);
    }

    public function test_a_pharmacy_only_practice_is_pointed_at_stock_categories(): void
    {
        app(PracticeService::class)->choose($this->h, 'dental_clinic', [], ['pharmacy']);
        $this->as('hospital_admin');

        $steps = collect($this->getJson('/api/v1/admin/onboarding')->json('data.steps'))->keyBy('key');

        $this->assertSame('stock_categories', $steps['catalogues']['starter_kind']);
        $this->assertSame('admin.stock-categories.index', $steps['catalogues']['app_route']);
        $this->assertArrayNotHasKey('wards', $steps->all());
    }
}
