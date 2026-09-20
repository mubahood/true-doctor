<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * K4 — the super-admin UI wrote `max_users` into a plan's `limits` JSON while
 * App\Support\PlanLimit (and PlanSeeder) read `max_staff`, so the staff cap of
 * every UI-edited plan was silently unlimited. The UI now writes `max_staff`;
 * this rewrites the rows it already mis-keyed. Driver-agnostic on purpose:
 * `limits` is a JSON text column and the table is tiny.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rekey('max_users', 'max_staff');
    }

    public function down(): void
    {
        $this->rekey('max_staff', 'max_users');
    }

    private function rekey(string $from, string $to): void
    {
        foreach (DB::table('plans')->select('id', 'limits')->get() as $plan) {
            $limits = json_decode((string) $plan->limits, true);
            if (! is_array($limits) || ! array_key_exists($from, $limits)) {
                continue;
            }

            // An existing correctly-keyed value wins; the stale key is dropped.
            $limits[$to] ??= $limits[$from];
            unset($limits[$from]);

            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        }
    }
};
