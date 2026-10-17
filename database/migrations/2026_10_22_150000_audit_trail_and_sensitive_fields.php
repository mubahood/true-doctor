<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records that cannot be changed or read quietly (roadmap 07 §3, docs/audit.md).
 *
 * 1. `record_accesses`: who opened or printed a patient's record, when, from
 *    where. Append-only; one row per person, patient and action in a short
 *    window, so a page that re-renders does not bury the trail.
 * 2. `visits.sensitive_notes`: the visit's confidential note (HIV status,
 *    mental health, sexual and reproductive health), encrypted at rest by the
 *    model's cast.
 * 3. `patients.notes` encrypted at rest. Existing rows are encrypted here, in
 *    chunks, and a value that already decrypts is left alone, so running this
 *    twice (or after a half-finished run) never double-encrypts anything.
 * 4. Patient change entries written before the trail carried the tenant get
 *    their hospital, so the per-hospital audit screen shows them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('record_accesses')) {
            Schema::create('record_accesses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hospital_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('patient_id');
                $table->string('action', 20);     // opened · printed
                $table->string('channel', 10);    // web · api
                $table->string('context', 120)->nullable(); // "Visit report V-000123"
                $table->string('ip', 45)->nullable();
                $table->string('device', 191)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['hospital_id', 'patient_id', 'created_at'], 'record_accesses_patient_idx');
                $table->index(['hospital_id', 'user_id', 'created_at'], 'record_accesses_user_idx');
                $table->index(['hospital_id', 'created_at'], 'record_accesses_hospital_idx');
            });
        }

        if (! Schema::hasColumn('visits', 'sensitive_notes')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->text('sensitive_notes')->nullable();
            });
        }

        $this->encryptColumn('patients', 'notes');

        if (Schema::hasColumn('activity_log', 'hospital_id')) {
            DB::table('activity_log')
                ->whereNull('hospital_id')
                ->where('subject_type', 'App\\Models\\Patient')
                ->update(['hospital_id' => DB::raw('(select hospital_id from patients where patients.id = activity_log.subject_id)')]);
        }
    }

    public function down(): void
    {
        $this->decryptColumn('patients', 'notes');

        if (Schema::hasColumn('visits', 'sensitive_notes')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->dropColumn('sensitive_notes');
            });
        }

        Schema::dropIfExists('record_accesses');
    }

    /** Encrypt every plain value, a chunk at a time; a value that already decrypts is skipped. */
    private function encryptColumn(string $table, string $column): void
    {
        DB::table($table)->whereNotNull($column)->where($column, '!=', '')
            ->select(['id', $column])
            ->chunkById(500, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    $value = (string) $row->{$column};
                    if ($this->isEncrypted($value)) {
                        continue;
                    }
                    DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString($value)]);
                }
            });
    }

    private function decryptColumn(string $table, string $column): void
    {
        DB::table($table)->whereNotNull($column)->where($column, '!=', '')
            ->select(['id', $column])
            ->chunkById(500, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    try {
                        $plain = Crypt::decryptString((string) $row->{$column});
                    } catch (DecryptException) {
                        continue;
                    }
                    DB::table($table)->where('id', $row->id)->update([$column => $plain]);
                }
            });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
