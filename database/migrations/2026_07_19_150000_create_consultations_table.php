<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An OPD encounter (HMS_PLAN.md §4). consultation_no is generated per hospital
 * per day (ConsultationService). Vitals include a computed BMI. The status
 * machine lives in App\Enums\ConsultationStatus; billing rollups arrive in
 * Step 11 (Billing v1) as an additive migration — this table is clinical-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('consultation_no');
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('receptionist_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason')->nullable();
            $table->text('complaints')->nullable();
            $table->text('diagnosis')->nullable();

            // Vitals
            $table->decimal('temperature', 4, 1)->nullable();   // °C
            $table->decimal('weight', 5, 2)->nullable();        // kg
            $table->decimal('height', 5, 2)->nullable();        // cm
            $table->decimal('bmi', 5, 2)->nullable();           // computed
            $table->unsignedSmallInteger('pulse')->nullable();  // bpm
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedSmallInteger('spo2')->nullable();   // %
            $table->string('blood_pressure', 12)->nullable();   // e.g. 120/80
            $table->dateTime('vitals_recorded_at')->nullable();

            $table->text('doctor_remarks')->nullable();
            $table->text('receptionist_remarks')->nullable();
            $table->text('patient_remarks')->nullable();

            $table->string('status', 16)->default('registration');
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'consultation_no']);
            $table->index(['hospital_id', 'status', 'created_at']);
            $table->index(['hospital_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
