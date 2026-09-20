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
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
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
