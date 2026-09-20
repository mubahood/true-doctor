<?php

namespace Tests\Feature\Livewire;

use App\Livewire\FinancialYears\Show as FinancialYearShow;
use App\Livewire\Patients\TreatmentShow;
use App\Livewire\Stock\Show as StockShow;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Services\StockService;
use App\Services\TreatmentService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Full-page smoke for the three Phase 3 detail screens that replaced the last
 * classic show views (stock item, financial-year report, treatment record):
 * the route resolves to the component, the admin layout renders it and the
 * server-rendered <title> comes from ->title() (house rule 3, finding A5).
 *
 * The behavioural assertions (ledger invariants, period transitions, photo
 * access control, RBAC, tenancy) live with their domain suites —
 * StockHttpTest, FinancialYearHttpTest and TreatmentRecordTest.
 */
class StockFinanceTreatmentShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Storage::fake('local');
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_stock_show_renders_the_summary_and_the_ledger(): void
    {
        $h = Hospital::factory()->create();
        $pharm = $this->user($h, 'pharmacist');
        app(CurrentHospital::class)->set($h->id);

        $item = StockItem::factory()->create([
            'hospital_id' => $h->id, 'name' => 'Amoxicillin 250mg', 'unit' => 'capsules',
            'cost_price' => '0.50', 'sale_price' => '1.20', 'reorder_level' => '20',
        ]);
        app(StockService::class)->receive($item, '100', '0.50', $pharm->id, 'Opening delivery');

        Livewire::actingAs($pharm)->test(StockShow::class, ['stock' => $item->uuid])
            ->assertOk()
            ->assertSet('itemId', $item->id)
            ->assertSee('Amoxicillin 250mg')
            ->assertSee('capsules')
            ->assertSee('Received')             // ledger row type
            ->assertSee('Opening delivery')     // ledger note
            ->assertSee('Movement ledger');

        $this->actingAs($pharm)->get("/admin/stock/{$item->uuid}")
            ->assertOk()
            ->assertSee('Amoxicillin 250mg')
            ->assertSee('<title>Amoxicillin 250mg · True-Doctor</title>', false);
    }

    public function test_stock_show_warns_about_low_stock_and_expiry(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create([
            'hospital_id' => $h->id, 'name' => 'Adrenaline', 'current_quantity' => '2',
            'reorder_level' => '20', 'expiry_date' => now()->subDay()->toDateString(),
        ]);

        Livewire::actingAs($this->user($h, 'pharmacist'))->test(StockShow::class, ['stock' => $item->uuid])
            ->assertOk()
            ->assertSee('Low stock')
            ->assertSee('Expired');
    }

    public function test_financial_year_show_renders_the_report_tiles(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $fy = FinancialYear::create(['hospital_id' => $h->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        $accountant = $this->user($h, 'accountant');

        Livewire::actingAs($accountant)->test(FinancialYearShow::class, ['financialYear' => $fy->id])
            ->assertOk()
            ->assertSee('Payments received')
            ->assertSee('Payments by method')
            ->assertSee('Open');

        $this->actingAs($accountant)->get("/admin/financial-years/{$fy->id}")
            ->assertOk()
            ->assertSee('<title>FY 2026 · True-Doctor</title>', false);
    }

    public function test_treatment_show_renders_photos_as_plain_links_out_of_the_spa(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->user($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        app(CurrentHospital::class)->set($h->id);

        /** @var TreatmentRecord $record */
        $record = app(TreatmentService::class)->create(
            $patient,
            ['procedure' => 'Plaster cast'],
            [UploadedFile::fake()->image('arm.jpg')],
            $doctor->id,
        );
        $photo = $record->photos()->firstOrFail();

        Livewire::actingAs($doctor)->test(TreatmentShow::class, ['patient' => $patient->uuid, 'treatment' => $record->uuid])
            ->assertOk()
            ->assertSee('Plaster cast')
            ->assertSeeHtml('rel="noopener"')
            ->assertSee('(opens in a new tab)')
            ->assertSeeHtml(route('admin.patients.treatments.photo', [$patient, $photo]));

        $this->actingAs($doctor)->get("/admin/patients/{$patient->uuid}/treatments/{$record->uuid}")
            ->assertOk()
            ->assertSee('<title>Plaster cast · True-Doctor</title>', false);
    }
}
