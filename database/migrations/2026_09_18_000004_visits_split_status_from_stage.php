<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One field answered two questions; now there are two — see docs/visits.md.
 *
 * `visits.status` held eight values that mixed *is this visit alive* with
 * *what is being done to it*, and folded four names for "the patient is here"
 * into the same list as two money steps. It becomes:
 *
 *   status   pending · ongoing · completed
 *   outcome  closed · cancelled          (only when completed)
 *   stage    ongoing · billing · payment · completed
 *
 * Guarded and resumable in the style of the orders migration. No money moves
 * and no visit is created or destroyed — only the words for where each one is.
 *
 * `visit_status_histories` is deliberately NOT rewritten. It is append-only,
 * and a row that says "Triage → Consultation" is a true record of what
 * happened; editing it to read something tidier would be falsifying an audit
 * trail. Its columns hold plain strings and the model reads both vocabularies.
 */
return new class extends Migration
{
    /** old visits.status => [new status, new stage, new outcome|null] */
    private const MAP = [
        'registration' => ['pending', 'ongoing', null],
        'triage' => ['ongoing', 'ongoing', null],
        'consultation' => ['ongoing', 'ongoing', null],
        'orders' => ['ongoing', 'ongoing', null],
        'billing' => ['ongoing', 'billing', null],
        'payment' => ['ongoing', 'payment', null],
        'completed' => ['completed', 'completed', 'closed'],
        'cancelled' => ['completed', 'ongoing', 'cancelled'],
    ];

    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            if (! Schema::hasColumn('visits', 'stage')) {
                $table->string('stage', 20)->default('ongoing')->after('status');
            }
            if (! Schema::hasColumn('visits', 'outcome')) {
                $table->string('outcome', 20)->nullable()->after('stage');
            }
        });

        // Old vocabulary to new, one statement per old value. Anything already
        // carrying a new value is left alone, so a half-run migration re-runs.
        foreach (self::MAP as $old => [$status, $stage, $outcome]) {
            DB::table('visits')->where('status', $old)->update([
                'status' => $status,
                'stage' => $stage,
                'outcome' => $outcome,
            ]);
        }

        // `registration` meant "at the front desk", and a visit could sit there
        // with orders already raised on it. In the new model that is not a
        // state a visit can be in: work on it means it has started. Mapping on
        // the old word alone left those rows saying Pending while their own
        // orders said otherwise.
        DB::table('visits')
            ->where('status', 'pending')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('orders')
                ->whereColumn('orders.visit_id', 'visits.id')
                ->whereNull('orders.deleted_at'))
            ->update(['status' => 'ongoing']);

        Schema::table('visits', function (Blueprint $table) {
            $table->index(['hospital_id', 'stage']);
        });

        // The trail keeps its own words, but new rows carry the stage too.
        Schema::table('visit_status_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('visit_status_histories', 'from_stage')) {
                $table->string('from_stage', 20)->nullable()->after('to_status');
            }
            if (! Schema::hasColumn('visit_status_histories', 'to_stage')) {
                $table->string('to_stage', 20)->nullable()->after('from_stage');
            }
        });
    }

    public function down(): void
    {
        // Back to the single field. The four Ongoing names collapsed into one
        // on the way here and cannot be told apart again, so every ongoing
        // visit comes back as `consultation` — the one it spent longest in.
        DB::table('visits')->where('status', 'pending')->update(['status' => 'registration']);
        DB::table('visits')->where('status', 'completed')->where('outcome', 'cancelled')->update(['status' => 'cancelled']);
        DB::table('visits')->where('status', 'ongoing')->where('stage', 'billing')->update(['status' => 'billing']);
        DB::table('visits')->where('status', 'ongoing')->where('stage', 'payment')->update(['status' => 'payment']);
        DB::table('visits')->where('status', 'ongoing')->update(['status' => 'consultation']);

        if ($this->hasIndex('visits', 'visits_hospital_id_stage_index')) {
            Schema::table('visits', fn (Blueprint $table) => $table->dropIndex(['hospital_id', 'stage']));
        }

        Schema::table('visits', function (Blueprint $table) {
            foreach (['stage', 'outcome'] as $column) {
                if (Schema::hasColumn('visits', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('visit_status_histories', function (Blueprint $table) {
            foreach (['from_stage', 'to_stage'] as $column) {
                if (Schema::hasColumn('visit_status_histories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $i) => ($i['name'] ?? '') === $index);
    }
};
