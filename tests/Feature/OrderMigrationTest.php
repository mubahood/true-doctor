<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The gate on the whole change: charges became orders WITHOUT MOVING MONEY.
 *
 * A hospital's existing bills predate orders entirely — loose `medical_services`
 * rows hanging off a visit, named by hand ("Lab: Malaria RDT", "Bed charge — 3
 * night(s)"). The migration gives each of them an order to belong to. What it
 * must never do is change what anyone owes.
 *
 * So these tests genuinely go back: the migration is rolled back, rows are
 * written in the old shape into the old table, and the real `up()` is run over
 * them. Nothing is simulated — a copy of the backfill could drift from the one
 * that actually runs in production.
 */
class OrderMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => $patient->id,
        ]);
    }

    /**
     * Undo the orders migration, leaving the database as it was before it.
     *
     * Rolled back a step at a time until the old table is back rather than by
     * a fixed count: every migration added after the orders one would
     * otherwise silently make this test roll back the wrong thing. The cap is
     * only a runaway guard, so it is set far above any plausible count.
     */
    private function rollBackToBeforeOrders(): void
    {
        for ($step = 0; $step < 60 && ! Schema::hasTable('medical_services'); $step++) {
            $this->artisan('migrate:rollback', ['--step' => 1])->run();
        }

        $this->assertTrue(Schema::hasTable('medical_services'), 'the rollback did not restore the old table');
        $this->assertFalse(Schema::hasTable('orders'), 'the rollback left orders behind');
        $this->assertTrue(Schema::hasColumn('medical_services', 'visit_id'), 'the rollback did not restore visit_id');
    }

    /** A line exactly as the old `addCustomLine` wrote it. */
    private function legacyLine(string $name, string $unitPrice, int $qty = 1, string $status = 'ordered'): void
    {
        DB::table('medical_services')->insert([
            'hospital_id' => $this->hospital->id,
            'visit_id' => $this->visit->id,
            'name' => $name,
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'line_total' => bcmul($unitPrice, (string) $qty, 2),
            'tax_exempt' => false,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int,object> the figures on the bill, by name */
    private function figures(string $table): array
    {
        return DB::table($table)->orderBy('name')
            ->get(['name', 'unit_price', 'quantity', 'line_total', 'tax_exempt', 'status'])
            ->all();
    }

    public function test_an_existing_bill_survives_the_migration_unchanged(): void
    {
        $this->rollBackToBeforeOrders();

        $this->legacyLine('Lab: Malaria RDT', '8000.00');
        $this->legacyLine('Radiology: Chest X-ray', '45000.00');
        $this->legacyLine('General consultation', '30000.00', 2);
        $this->legacyLine('Bed charge — 3 night(s)', '90000.00');
        $this->legacyLine('Cancelled thing', '5000.00', 1, 'cancelled');

        $before = $this->figures('medical_services');

        $this->artisan('migrate')->run();

        $after = $this->figures('order_items');

        $this->assertEquals($before, $after, 'the migration changed a figure on an existing bill');
        $this->assertSame(0, DB::table('order_items')->whereNull('order_id')->count(), 'a line was left with no order');
    }

    public function test_the_name_prefix_decides_the_type_of_the_order_it_gets(): void
    {
        $this->rollBackToBeforeOrders();

        $this->legacyLine('Lab: Malaria RDT', '8000.00');
        $this->legacyLine('Radiology: Chest X-ray', '45000.00');
        $this->legacyLine('Bed charge — 3 night(s)', '90000.00');
        $this->legacyLine('General consultation', '30000.00');

        $this->artisan('migrate')->run();

        $types = DB::table('orders')->orderBy('type')->pluck('type')->unique()->values()->all();
        $this->assertSame(['admission', 'consultation', 'imaging', 'lab'], $types);
    }

    /** Carried-over work is history, not a pile of new jobs for the worklist. */
    public function test_carried_over_orders_are_closed(): void
    {
        $this->rollBackToBeforeOrders();
        $this->legacyLine('General consultation', '30000.00');

        $this->artisan('migrate')->run();

        $this->assertSame(
            0,
            DB::table('orders')->whereIn('status', ['pending', 'in_progress'])->count(),
            'old bills reopened as pending work',
        );
        $this->assertSame(1, DB::table('orders')->where('status', 'completed')->count());
    }

    /** Every carried-over line lands on an order belonging to its own visit. */
    public function test_a_line_stays_on_its_own_visit(): void
    {
        // Made BEFORE the rollback: rolling back takes `visits.stage` with it,
        // and the factory writes that column. What this test is about is where
        // the LINES land, so the visits may as well exist first.
        $other = Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);

        $this->rollBackToBeforeOrders();

        $this->legacyLine('General consultation', '30000.00');
        DB::table('medical_services')->insert([
            'hospital_id' => $this->hospital->id,
            'visit_id' => $other->id,
            'name' => 'Dressing',
            'unit_price' => '1000.00', 'quantity' => 1, 'line_total' => '1000.00',
            'tax_exempt' => false, 'status' => 'ordered',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('migrate')->run();

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->pluck('orders.visit_id', 'order_items.name');

        $this->assertSame($this->visit->id, (int) $rows['General consultation']);
        $this->assertSame($other->id, (int) $rows['Dressing']);
    }
}
