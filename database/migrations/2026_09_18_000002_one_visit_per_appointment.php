<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An appointment turns into exactly one visit.
 *
 * `visits.appointment_id` has been nullable and unconstrained since the column
 * was added, so checking a patient in twice — a double-click, two receptionists,
 * a retried request — produced two visits against one appointment, and with
 * them two bills for one attendance. Now that the new-visit form can link an
 * appointment, the database has to be the thing that says "once".
 *
 * Both engines allow many NULLs in a unique index, so unbooked walk-ins are
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Any duplicates are that bug's leftovers. The earliest visit keeps the
        // link — it is the one the check-in actually produced — and the later
        // ones simply stop claiming an appointment they did not create. No
        // visit, charge or payment is touched.
        $duplicates = DB::table('visits')
            ->select('appointment_id')
            ->whereNotNull('appointment_id')
            ->groupBy('appointment_id')
            ->havingRaw('count(*) > 1')
            ->pluck('appointment_id');

        foreach ($duplicates as $appointmentId) {
            $keep = DB::table('visits')->where('appointment_id', $appointmentId)->min('id');
            DB::table('visits')
                ->where('appointment_id', $appointmentId)
                ->where('id', '!=', $keep)
                ->update(['appointment_id' => null]);
        }

        if (! $this->hasIndex('visits', 'visits_appointment_id_unique')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->unique('appointment_id');
            });
        }
    }

    /**
     * MySQL absorbs the foreign key's own index into the unique one, so the
     * unique index cannot simply be dropped — the constraint still needs an
     * index on the column (errno 1553). There the key comes off first and goes
     * back on afterwards, which recreates the plain index with it.
     *
     * SQLite has neither the problem nor the ability to drop a key by name: it
     * rebuilds the table, and the unique index goes with a plain drop.
     */
    public function down(): void
    {
        if (! $this->hasIndex('visits', 'visits_appointment_id_unique')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            Schema::table('visits', fn (Blueprint $table) => $table->dropUnique('visits_appointment_id_unique'));

            return;
        }

        // Named, not guessed: the table was renamed from `consultations`, so
        // its keys still carry the old prefix and dropForeign(['appointment_id'])
        // would look for a name that has never existed here.
        $foreignKey = collect(Schema::getForeignKeys('visits'))
            ->first(fn (array $k) => $k['columns'] === ['appointment_id']);

        Schema::table('visits', function (Blueprint $table) use ($foreignKey) {
            if ($foreignKey !== null) {
                $table->dropForeign($foreignKey['name'] ?: ['appointment_id']);
            }

            $table->dropUnique('visits_appointment_id_unique');

            if ($foreignKey !== null) {
                $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $i) => ($i['name'] ?? '') === $index);
    }
};
