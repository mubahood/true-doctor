<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A procedure performed on a patient (dressing, minor surgery, dental work, …),
 * building the patient's treatment timeline. Specialty-specific charting (e.g. a
 * dental tooth-map) lives in the `meta` JSON, not a bespoke table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_records', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // `consultations`, not `visits`: the hub is not renamed until
            // 2026_09_16_000002, and a foreign key can only point at a table
            // that exists NOW. `constrained()` would infer `visits` from the
            // column name and fail on MySQL with "Cannot add foreign key
            // constraint" — SQLite does not enforce it at create time, which
            // is why the test suite never saw this. RENAME TABLE carries the
            // key across.
            $table->foreignId('visit_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('procedure');
            $table->text('description')->nullable();
            $table->json('meta')->nullable();          // specialty-specific chart data
            $table->dateTime('performed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_records');
    }
};
