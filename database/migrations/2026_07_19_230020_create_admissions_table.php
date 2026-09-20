<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An inpatient admission. bed_id is the current bed (changes via bed_transfers).
 * Bed charges are computed and billed to the linked visit on discharge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained()->nullOnDelete();
            // `consultations`, not `visits`: the hub is not renamed until
            // 2026_09_16_000002, and a foreign key can only point at a table
            // that exists NOW. `constrained()` would infer `visits` from the
            // column name and fail on MySQL with "Cannot add foreign key
            // constraint" — SQLite does not enforce it at create time, which
            // is why the test suite never saw this. RENAME TABLE carries the
            // key across.
            $table->foreignId('visit_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignId('admitting_doctor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('admitted');
            $table->string('reason')->nullable();
            $table->dateTime('admitted_at');
            $table->dateTime('discharged_at')->nullable();
            $table->text('discharge_notes')->nullable();
            $table->decimal('bed_charge_total', 12, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'status']);
            $table->index(['hospital_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
