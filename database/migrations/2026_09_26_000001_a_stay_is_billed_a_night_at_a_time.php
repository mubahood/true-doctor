<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many nights of this stay have been put on the bill.
 *
 * A stay used to be billed as ONE line at discharge — "Bed charge — 4
 * night(s)" — priced at whatever the bed cost on the day the patient left.
 * Three things were wrong with that:
 *
 *  - **The bill was invisible until the end.** A relative asking what it has
 *    cost so far got nothing, because nothing had been written down yet.
 *  - **A price change was retroactive.** The rate was read at discharge and
 *    multiplied by the whole stay, so putting the ward rate up on Friday
 *    repriced Monday's night too.
 *  - **It was not really an order's worth of work.** A stay is an order on a
 *    visit (docs/orders.md), and every other order accumulates the items it
 *    actually consumed.
 *
 * Now each night is an item on the stay's order, added as that night is
 * completed and priced at the rate in force then. This counter is what makes
 * that safe to run repeatedly: the job bills the difference between the
 * nights completed and the nights already billed, so running it twice, or
 * catching up after three days of downtime, bills each night exactly once.
 *
 * Existing open stays start at zero and are caught up on the first run, which
 * is correct: nothing has been billed for them yet either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->unsignedInteger('nights_billed')->default(0)->after('bed_charge_total');
        });

        // A stay that is already closed had its whole charge billed as one
        // line, so its nights are accounted for. Saying so stops the catch-up
        // job from ever looking at it again.
        //
        // In PHP rather than `DATEDIFF`, which SQLite does not have: the test
        // suite runs on SQLite and the raw version would migrate on MySQL and
        // fail everywhere else. That trap has already cost this project one
        // production bug (`devices.pull_cursor`).
        DB::table('admissions')
            ->whereNotNull('discharged_at')
            ->select('id', 'admitted_at', 'discharged_at')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $nights = max(1, (int) Carbon::parse($row->admitted_at)
                        ->startOfDay()
                        ->diffInDays(Carbon::parse($row->discharged_at)->startOfDay()));

                    DB::table('admissions')->where('id', $row->id)->update(['nights_billed' => $nights]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropColumn('nights_billed');
        });
    }
};
