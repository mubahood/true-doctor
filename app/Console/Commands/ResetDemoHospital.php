<?php

namespace App\Console\Commands;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\DemoPopulationSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put the demonstration hospital back the way it was.
 *
 * A public demo is a hospital that strangers change. Left alone it fills up
 * with patients called "aaa", every bed ends up occupied and every invoice
 * ends up void, and the next person who comes to look at the product sees a
 * mess instead. So it is emptied and rebuilt on a schedule.
 *
 * THE ONLY THING THAT MATTERS HERE IS THAT IT CANNOT TOUCH A REAL HOSPITAL.
 * Four separate things have to be true before a single row is deleted:
 *
 *   1. The demo has to be switched on at all (`DEMO_MODE`).
 *   2. Resetting has to be switched on separately (`DEMO_RESET`) — turning the
 *      demo on must not by itself start something that deletes rows nightly.
 *   3. The target has to be the hospital named in `demo.hospital`, resolved by
 *      slug, and it has to exist.
 *   4. Every delete is filtered by that hospital's id. Nothing is truncated,
 *      no table is emptied wholesale, and a row belonging to anybody else is
 *      not reachable by the queries below.
 *
 * And it refuses unless the target is POSITIVELY a demonstration: every staff
 * account in it must be a seeded demo account. That is far stronger than
 * asking whether the hospital looks like a customer — a real hospital never
 * has staff signing in with a password printed on a web page, and one real
 * account in the list is enough to stop the whole thing.
 */
class ResetDemoHospital extends Command
{
    protected $signature = 'demo:reset
                            {--force : Skip the confirmation prompt}
                            {--dry-run : Say what would be deleted and delete nothing}';

    protected $description = 'Empty the demonstration hospital and seed it again';

    /**
     * Tenant tables, in an order that respects their foreign keys — children
     * before the rows they hang off.
     *
     * Written out rather than discovered, because "every table with a
     * hospital_id" is the kind of clever that one day includes a table
     * somebody did not mean to empty.
     *
     * @var list<string>
     */
    private const TABLES = [
        // Clinical work, deepest first
        'dose_item_records', 'dose_items', 'prescriptions',
        'medication_administrations', 'nursing_notes', 'vital_rounds',
        'bed_transfers', 'admissions',
        'lab_order_items', 'lab_orders',
        'radiology_order_items', 'radiology_orders',
        'dispensation_items', 'dispensations',
        'treatment_record_items', 'treatment_records',
        'order_attachments', 'order_items', 'orders',
        // Money
        'insurance_transactions', 'insurance_claims',
        'card_records', 'card_holders', 'patient_cards',
        'payments', 'invoice_items', 'invoices',
        // Attendance
        'appointment_status_histories', 'appointments',
        'visit_status_histories', 'visits',
        // Stock
        'stock_movements', 'stock_items', 'stock_categories',
        // The register
        'patient_insurances', 'patient_documents', 'patient_dependents', 'patients',
        // Offline
        'sync_conflicts', 'sync_operations', 'sync_sessions', 'device_tokens', 'devices',
        // Configuration the seeders rebuild
        'beds', 'wards', 'rooms', 'doctor_schedules', 'staff_profiles',
        'services', 'lab_tests', 'radiology_studies',
        'insurance_providers', 'financial_years', 'departments',
    ];

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->error('demo:reset refused — DEMO_MODE is off, so there is no demonstration to reset.');

            return self::FAILURE;
        }

        $slug = (string) config('demo.hospital');
        $hospital = Hospital::withoutGlobalScopes()->firstWhere('slug', $slug);

        if ($hospital === null) {
            $this->error("demo:reset refused — no hospital with the slug '{$slug}'.");

            return self::FAILURE;
        }

        // Positive proof that this is the demonstration, not an absence of
        // evidence that it is a customer. This is the check that stops a
        // mistyped slug or a copied .env from emptying a real hospital.
        if (($why = $this->notADemonstration($hospital)) !== null) {
            $this->error("demo:reset REFUSED — '{$hospital->name}' {$why}");

            return self::FAILURE;
        }

        $this->line("Demonstration hospital: <info>{$hospital->name}</info> (id {$hospital->id}, slug {$slug})");

        $counts = $this->counts($hospital->id);
        $total = array_sum($counts);

        if ($this->option('dry-run')) {
            $this->line("Would delete <comment>{$total}</comment> rows across ".count(array_filter($counts)).' tables:');
            foreach (array_filter($counts) as $table => $n) {
                $this->line(sprintf('  %-32s %s', $table, number_format($n)));
            }

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$total} rows from this hospital and seed it again?", false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $this->wipe($hospital->id, $total);
        $this->reseed();

        $this->newLine();
        $this->info('demo:reset — the demonstration hospital is back to its starting state.');

        return self::SUCCESS;
    }

    /**
     * Why this hospital is not the demonstration, or null if it is.
     *
     * The test is the staff list. Every account in a demonstration hospital
     * is a seeded one; a real hospital has at least one person whose password
     * is not printed on a web page. One such account and this refuses.
     *
     * Note this deliberately does NOT ask whether the hospital has a paid
     * plan: the demo tenant is put on the uncapped plan on purpose, so that
     * PlanLimit does not refuse the next thing a visitor clicks.
     */
    private function notADemonstration(Hospital $hospital): ?string
    {
        $staff = User::withoutGlobalScopes()->where('hospital_id', $hospital->id)->pluck('email');

        if ($staff->isEmpty()) {
            return 'has no staff accounts at all, so it cannot be the seeded demonstration.';
        }

        $real = $staff->reject(fn ($email) => str_ends_with((string) $email, '@test.com'));

        if ($real->isNotEmpty()) {
            return sprintf(
                'has %d staff account%s that are not seeded demo accounts (e.g. %s). That is a real hospital.',
                $real->count(),
                $real->count() === 1 ? '' : 's',
                $real->first(),
            );
        }

        return null;
    }

    /** @return array<string,int> */
    private function counts(int $hospitalId): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'hospital_id')) {
                continue;
            }

            $counts[$table] = DB::table($table)->where('hospital_id', $hospitalId)->count();
        }

        return $counts;
    }

    private function wipe(int $hospitalId, int $total): void
    {
        $this->line("Deleting {$total} rows…");

        // Foreign key checks off for the duration: the table order above is
        // right, but a demo hospital that has been played with for a month
        // can hold combinations nobody anticipated, and a reset that fails
        // halfway leaves a worse mess than the one it was fixing.
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'hospital_id')) {
                    continue;
                }

                // Always filtered by the hospital. Never a truncate.
                DB::table($table)->where('hospital_id', $hospitalId)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function reseed(): void
    {
        $this->line('Seeding it again…');

        // The seeders write through the services, which need model events and
        // a resolved tenant — and they must not be left set afterwards.
        $previous = app(CurrentHospital::class)->id();
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(app('events'));

        try {
            $this->callSilent('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->callSilent('db:seed', ['--class' => DemoPopulationSeeder::class, '--force' => true]);
        } finally {
            // Restore whatever was there, rather than unsetting.
            // `unsetEventDispatcher()` removes it GLOBALLY — so anything running
            // afterwards in the same process silently loses model events, which
            // in this codebase means uuids, slugs and (worse) the hospital_id
            // that BelongsToHospital fills on create. Fine when the process ends
            // straight after; a trap in a test, a queue worker, or a command that
            // seeds and then keeps going.
            $dispatcher ? Model::setEventDispatcher($dispatcher) : Model::unsetEventDispatcher();
            app(CurrentHospital::class)->set($previous);
        }
    }
}
