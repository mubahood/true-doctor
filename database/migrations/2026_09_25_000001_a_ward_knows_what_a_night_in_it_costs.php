<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a night in this ward costs.
 *
 * The charge that is actually billed has always lived on the BED
 * (`beds.daily_charge`) and still does — a side room and a bay in the same
 * ward are not worth the same, and `AdmissionService::discharge` reads the
 * bed. This column is the ward's standard rate: the figure a new bed starts
 * from, and the one an administrator sets when the whole ward is repriced.
 *
 * It is deliberately NOT the source of truth for billing. Making it so would
 * mean a bed could not differ from its ward, and the first time somebody
 * needed a private room to cost more they would have to fight the model.
 *
 * Backfilled from the beds already in each ward — the most common rate among
 * them, which is the answer somebody would give if asked "what does this ward
 * cost?" — so an existing hospital opens the screen and sees its own figures
 * rather than zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wards', function (Blueprint $table) {
            $table->decimal('default_daily_charge', 12, 2)->default(0)->after('description');
        });

        // The commonest bed rate in each ward. `MAX` breaks a tie, which only
        // matters when a ward is evenly split between two rates and either
        // answer is equally defensible.
        foreach (DB::table('wards')->select('id')->get() as $ward) {
            $rate = DB::table('beds')
                ->where('ward_id', $ward->id)
                ->whereNull('deleted_at')
                ->groupBy('daily_charge')
                ->orderByRaw('COUNT(*) DESC')
                ->orderByRaw('MAX(daily_charge) DESC')
                ->value('daily_charge');

            if ($rate !== null) {
                DB::table('wards')->where('id', $ward->id)->update(['default_daily_charge' => $rate]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('wards', function (Blueprint $table) {
            $table->dropColumn('default_daily_charge');
        });
    }
};
