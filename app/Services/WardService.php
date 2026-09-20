<?php

namespace App\Services;

use App\Enums\BedStatus;
use App\Models\Bed;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;

/**
 * Repricing a ward.
 *
 * Small, but it owns a rule that must not be spread across screens: setting
 * every bed in a ward to one nightly charge is a MONEY operation, and the
 * money it moves is not only future money.
 *
 * `AdmissionService::discharge` reads `beds.daily_charge` **at discharge** and
 * multiplies it by the whole stay:
 *
 *     $rate   = (string) $bed->daily_charge;
 *     $charge = bcmul($rate, (string) $nights, 2);
 *
 * So a bed that is occupied right now has its entire stay repriced — every
 * night already spent in it, at a rate that was not in force when those nights
 * were spent. That is sometimes exactly what an administrator means ("we put
 * the rate up, bill everybody the new one") and sometimes the last thing they
 * meant. It is not this service's place to decide which; it is this service's
 * place to make sure nobody does it without being told, which is why it
 * reports how many occupied beds it touched and the screen says so before the
 * button is pressed.
 */
class WardService
{
    /**
     * Set every bed in the ward to one nightly charge.
     *
     * @param  numeric-string  $charge
     * @return array{beds:int,occupied:int} what was changed, and how much of
     *                                      it was somebody's bed tonight
     */
    public function applyNightlyChargeToBeds(Ward $ward, string $charge): array
    {
        return DB::transaction(function () use ($ward, $charge) {
            // Locked for the same reason a discharge locks the bed it reads:
            // a discharge running in the next connection must see either the
            // old rate or the new one, never a table halfway between.
            $beds = Bed::withoutGlobalScopes()
                ->where('hospital_id', $ward->hospital_id)
                ->where('ward_id', $ward->id)
                ->lockForUpdate()
                ->get();

            $occupied = $beds->where('status', BedStatus::Occupied)->count();

            foreach ($beds as $bed) {
                // One at a time rather than a mass `update()`, so each row's
                // activity log records who changed the price of that bed.
                // A bulk statement would leave the ledger silent about the
                // single most consequential edit on this screen.
                $bed->update(['daily_charge' => $charge]);
            }

            return ['beds' => $beds->count(), 'occupied' => $occupied];
        });
    }

    /**
     * How many beds a reprice would touch, and how many are occupied.
     *
     * Asked BEFORE the change, so the screen can say what is about to happen
     * rather than reporting it afterwards.
     *
     * @return array{beds:int,occupied:int}
     */
    public function repriceImpact(Ward $ward): array
    {
        $beds = Bed::where('ward_id', $ward->id)->get(['status']);

        return [
            'beds' => $beds->count(),
            'occupied' => $beds->where('status', BedStatus::Occupied)->count(),
        ];
    }
}
