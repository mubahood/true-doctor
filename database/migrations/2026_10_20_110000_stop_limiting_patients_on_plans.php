<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans no longer cap patients (docs/decisions.md, 2026-10-17): a clinic is
 * never stopped from registering the patient at its desk. PlanLimit already
 * ignores the key; this takes it out of the stored plans so no screen, app or
 * price list quotes a limit that is not enforced, and rewrites the matching
 * feature line. Reversing it does not put the cap back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        foreach (DB::table('plans')->get(['id', 'limits', 'features']) as $plan) {
            $limits = json_decode((string) $plan->limits, true);
            $features = json_decode((string) $plan->features, true);
            $changed = false;

            if (is_array($limits) && array_key_exists('max_patients', $limits)) {
                unset($limits['max_patients']);
                $changed = true;
            }
            if (is_array($features)) {
                foreach ($features as $i => $line) {
                    if (is_string($line) && preg_match('/^Up to [\d,]+ patients$/i', $line) === 1) {
                        $features[$i] = 'Unlimited patients';
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                DB::table('plans')->where('id', $plan->id)->update([
                    'limits' => json_encode(is_array($limits) ? $limits : []),
                    'features' => is_array($features) ? json_encode(array_values($features)) : $plan->features,
                ]);
            }
        }
    }

    public function down(): void
    {
        // The cap was the mistake; there is nothing to restore.
    }
};
