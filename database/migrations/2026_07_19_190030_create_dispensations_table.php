<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A dispensing event against a visit (optionally a prescription). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            // `consultations`, not `visits`: the hub is not renamed until
            // 2026_09_16_000002, and a foreign key can only point at a table
            // that exists NOW. `constrained()` would infer `visits` from the
            // column name and fail on MySQL with "Cannot add foreign key
            // constraint" — SQLite does not enforce it at create time, which
            // is why the test suite never saw this. RENAME TABLE carries the
            // key across.
            $table->foreignId('visit_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('dispensed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'visit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensations');
    }
};
