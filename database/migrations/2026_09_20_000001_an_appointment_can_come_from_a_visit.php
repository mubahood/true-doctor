<?php

use App\Support\SchemaKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an appointment came from.
 *
 * TWO DIFFERENT LINKS, AND THEY MUST NOT BE CONFUSED.
 *
 *   visits.appointment_id      the visit a booking BECAME. Somebody booked for
 *                              Tuesday, arrived on Tuesday, and the check-in
 *                              opened a visit against that booking. It points
 *                              forwards in time.
 *
 *   appointments.origin_visit_id   the visit a booking CAME OUT OF. "Come back
 *                              in two weeks" said at the end of a consultation.
 *                              It points backwards in time.
 *
 * Until now only the first existed, so a follow-up arranged in the consulting
 * room arrived at the diary as a patient picked out of the register with
 * nothing tying it to the attendance that prompted it — and the clinician who
 * sees them in two weeks has no way back to the visit that sent them.
 *
 * Nullable, because most bookings are still cold: somebody rings up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'origin_visit_id')) {
                $table->unsignedBigInteger('origin_visit_id')->nullable()->after('patient_id');
            }
        });

        if (! $this->hasIndex('appointments', 'appt_origin_visit_idx')) {
            Schema::table('appointments', fn (Blueprint $table) => $table
                ->index(['hospital_id', 'origin_visit_id'], 'appt_origin_visit_idx'));
        }

        // Through SchemaKeys: `appointments` is referenced by `visits` and by
        // `appointment_status_histories`, so rebuilding it to add a key in
        // place would cascade through both.
        SchemaKeys::add('appointments', 'origin_visit_id', 'visits');
    }

    public function down(): void
    {
        SchemaKeys::drop('appointments', 'origin_visit_id');

        if ($this->hasIndex('appointments', 'appt_origin_visit_idx')) {
            Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex('appt_origin_visit_idx'));
        }

        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'origin_visit_id')) {
                $table->dropColumn('origin_visit_id');
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
