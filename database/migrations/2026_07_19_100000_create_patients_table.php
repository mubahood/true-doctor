<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patients — first-class tenant entity (HMS_PLAN.md §4, B6). Never a flag on the
 * staff table. Scoped by hospital_id; patient_no is unique *per hospital*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('patient_no', 32);

            // Demographics
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob')->nullable();
            $table->string('sex', 10)->nullable();

            // Contacts
            $table->string('phone_1', 32)->nullable();
            $table->string('phone_2', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('home_address')->nullable();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();

            // Medical
            $table->string('blood_type', 8)->nullable();
            $table->json('allergies')->nullable();
            $table->json('chronic_conditions')->nullable();

            // Family / emergency
            $table->string('spouse_name')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();

            // Insurance (light — full insurance module is Phase 4)
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_member_no')->nullable();

            // Sensitive extras — encrypted at rest (C12)
            $table->text('bank_details')->nullable();

            // Consent & meta
            $table->boolean('consent_given')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->string('photo')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('active');

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Tenancy: patient_no unique *within* a hospital (B8 composite unique).
            $table->unique(['hospital_id', 'patient_no']);
            $table->index(['hospital_id', 'last_name', 'first_name']);
            $table->index(['hospital_id', 'phone_1']);
            $table->index(['hospital_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
