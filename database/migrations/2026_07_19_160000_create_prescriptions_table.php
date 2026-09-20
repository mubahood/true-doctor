<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A prescription written during a visit; groups its dose items. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
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
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescribed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'visit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
