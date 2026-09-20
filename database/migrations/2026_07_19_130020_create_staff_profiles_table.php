<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical/HR extension of a staff `users` row (HMS_PLAN.md §4): specialty,
 * licence, qualifications, signature and a weekly availability template. One
 * profile per user; both the profile and its user belong to the same hospital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            $table->string('job_title')->nullable();
            $table->string('specialty')->nullable();
            $table->string('license_no')->nullable();
            $table->string('qualifications')->nullable();
            $table->string('signature')->nullable();      // private-disk path
            $table->json('schedule')->nullable();          // weekly availability template
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');                     // one profile per user
            $table->index(['hospital_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
