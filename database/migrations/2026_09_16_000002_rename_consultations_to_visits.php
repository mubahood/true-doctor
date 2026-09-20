<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The core of the system is one patient attendance, and everything the patient
 * does that day hangs off it. That thing was called a "consultation", which is
 * really only one STAGE of it — the status flow has always known better:
 * registration → triage → consultation → orders → billing → payment. A
 * lab-only walk-in or a six-day admission is not a consultation; it is a
 * visit. So the hub becomes `visits`, and "consultation" keeps the narrow
 * clinical meaning it should always have had (the stage, the fee, the room).
 *
 * This also closes the hole in "everything belongs to a visit": invoices and
 * admissions could previously float free of one. Orphans are given a visit
 * before the columns are tightened, so no row is lost.
 *
 * Every step is guarded. MySQL cannot roll back DDL, so a migration this wide
 * must be able to resume from wherever it stopped rather than leaving a
 * database that can neither go forward nor back.
 */
return new class extends Migration
{
    /** child table => the column that pointed at a consultation */
    private const CHILDREN = [
        'lab_orders', 'radiology_orders', 'prescriptions', 'dispensations',
        'treatment_records', 'medical_services', 'invoices', 'admissions',
        'visit_status_histories',
    ];

    /** Clinical and billable records that must not exist without a visit. */
    private const MUST_HAVE_VISIT = ['invoices', 'admissions', 'treatment_records'];

    public function up(): void
    {
        if (Schema::hasTable('consultations')) {
            Schema::rename('consultations', 'visits');
        }
        if (Schema::hasTable('consultation_status_histories')) {
            Schema::rename('consultation_status_histories', 'visit_status_histories');
        }

        if (Schema::hasColumn('visits', 'consultation_no')) {
            Schema::table('visits', fn (Blueprint $table) => $table->renameColumn('consultation_no', 'visit_no'));
        }

        foreach (self::CHILDREN as $child) {
            if (Schema::hasColumn($child, 'consultation_id')) {
                Schema::table($child, fn (Blueprint $table) => $table->renameColumn('consultation_id', 'visit_id'));
            }
        }

        // C-20260914-001 → V-20260914-001, so the number matches the noun.
        // Done in PHP, not SQL: string functions differ between MySQL and the
        // SQLite the test suite migrates on.
        $this->reprefixVisitNumbers('C-', 'V-');

        DB::table('sequences')->where('key', 'consultation')->update(['key' => 'visit']);

        $this->giveOrphansAVisit();

        // A visit's records die with it, like every other child of a visit.
        // The old constraints were ON DELETE SET NULL, and MySQL refuses to
        // make a column NOT NULL while a constraint on it may write NULL — so
        // each one is dropped, tightened, and put back with the right rule.
        foreach (self::MUST_HAVE_VISIT as $table) {
            $this->requireForeignKey($table, 'visit_id', 'visits');
        }

        // A claim is always raised against a bill, and a bill always belongs to
        // a visit — that is how a claim reaches one.
        DB::table('insurance_claims')->whereNull('invoice_id')->delete();
        $this->requireForeignKey('insurance_claims', 'invoice_id', 'invoices');

        // A document can be a patient-level artefact (an ID scan) or something
        // handed over during one visit (a referral letter) — hence optional.
        if (! Schema::hasColumn('patient_documents', 'visit_id')) {
            Schema::table('patient_documents', function (Blueprint $table) {
                $table->foreignId('visit_id')->nullable()->after('patient_id')->constrained()->nullOnDelete();
            });
        }

        $this->renamePermissions([
            'consultations.view' => 'visits.view',
            'consultations.create' => 'visits.create',
            'consultations.manage' => 'visits.manage',
            'consultations.vitals' => 'visits.vitals',
            'consultations.diagnose' => 'visits.diagnose',
        ]);
    }

    public function down(): void
    {
        $this->renamePermissions([
            'visits.view' => 'consultations.view',
            'visits.create' => 'consultations.create',
            'visits.manage' => 'consultations.manage',
            'visits.vitals' => 'consultations.vitals',
            'visits.diagnose' => 'consultations.diagnose',
        ]);

        if (Schema::hasColumn('patient_documents', 'visit_id')) {
            Schema::table('patient_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('visit_id'));
        }

        $this->relaxForeignKey('insurance_claims', 'invoice_id', 'invoices');

        foreach (self::MUST_HAVE_VISIT as $table) {
            $this->relaxForeignKey($table, 'visit_id', 'visits');
        }

        DB::table('sequences')->where('key', 'visit')->update(['key' => 'consultation']);

        $this->reprefixVisitNumbers('V-', 'C-');

        foreach (self::CHILDREN as $child) {
            if (Schema::hasColumn($child, 'visit_id')) {
                Schema::table($child, fn (Blueprint $table) => $table->renameColumn('visit_id', 'consultation_id'));
            }
        }

        if (Schema::hasColumn('visits', 'visit_no')) {
            Schema::table('visits', fn (Blueprint $table) => $table->renameColumn('visit_no', 'consultation_no'));
        }

        if (Schema::hasTable('visit_status_histories')) {
            Schema::rename('visit_status_histories', 'consultation_status_histories');
        }
        if (Schema::hasTable('visits')) {
            Schema::rename('visits', 'consultations');
        }
    }

    /** Make a nullable, SET NULL foreign key into a required, cascading one. */
    private function requireForeignKey(string $table, string $column, string $parent): void
    {
        $this->dropForeignKey($table, $column);

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId($column)->nullable(false)->change());
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($parent)->cascadeOnDelete());
    }

    /** The reverse: back to nullable and SET NULL. */
    private function relaxForeignKey(string $table, string $column, string $parent): void
    {
        $this->dropForeignKey($table, $column);

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId($column)->nullable()->change());
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($parent)->nullOnDelete());
    }

    /**
     * Drop whichever constraint actually sits on the column, by asking the
     * schema rather than guessing its name: renaming a column leaves the old
     * constraint name behind on MySQL, and rolling this migration back and
     * forward again leaves a different one, so no naming convention holds.
     * A column with no constraint is not an error — this has to be re-runnable.
     */
    private function dropForeignKey(string $table, string $column): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (! in_array($column, $foreignKey['columns'], true)) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(
                $foreignKey['name'] ?: [$column]
            ));
        }
    }

    private function reprefixVisitNumbers(string $from, string $to): void
    {
        DB::table('visits')->where('visit_no', 'like', $from.'%')
            ->orderBy('id')
            ->each(function (object $visit) use ($from, $to) {
                DB::table('visits')->where('id', $visit->id)->update([
                    'visit_no' => $to.substr($visit->visit_no, strlen($from)),
                ]);
            });
    }

    /**
     * Invoices and admissions that predate the rule get the visit they always
     * implied: one per patient per day, reused when several rows share a day,
     * marked completed because it is history.
     */
    private function giveOrphansAVisit(): void
    {
        foreach (self::MUST_HAVE_VISIT as $table) {
            foreach (DB::table($table)->whereNull('visit_id')->get() as $row) {
                $on = Carbon::parse($row->created_at ?? now());

                $existing = DB::table('visits')
                    ->where('hospital_id', $row->hospital_id)
                    ->where('patient_id', $row->patient_id)
                    ->whereDate('created_at', $on->toDateString())
                    ->value('id');

                DB::table($table)->where('id', $row->id)->update([
                    'visit_id' => $existing ?? $this->createBackfillVisit($row, $on),
                ]);
            }
        }
    }

    private function createBackfillVisit(object $row, Carbon $on): int
    {
        $sameDay = DB::table('visits')
            ->where('hospital_id', $row->hospital_id)
            ->whereDate('created_at', $on->toDateString())
            ->count();

        return DB::table('visits')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'hospital_id' => $row->hospital_id,
            'visit_no' => 'V-'.$on->format('Ymd').'-'.str_pad((string) ($sameDay + 1), 3, '0', STR_PAD_LEFT),
            'patient_id' => $row->patient_id,
            'reason' => 'Backfilled when consultations became visits',
            'status' => 'completed',
            'completed_at' => $on,
            'created_at' => $on,
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,string> $map */
    private function renamePermissions(array $map): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($map as $from => $to) {
            DB::table('permissions')->where('name', $from)->update(['name' => $to]);
        }

        if (app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
