<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guardian ↔ dependent links between two patient records (HMS_PLAN.md §4) —
 * the legacy is_dependent/dependent_id flags become a real relation. Both
 * sides are patients of the same hospital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();               // guardian
            $table->foreignId('dependent_patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('relationship', 32);     // child | spouse | ward | parent | other
            $table->string('status', 12)->default('active');
            $table->timestamps();

            $table->unique(['hospital_id', 'patient_id', 'dependent_patient_id'], 'patient_dependent_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_dependents');
    }
};
