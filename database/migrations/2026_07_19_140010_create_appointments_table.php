<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booked appointment. scheduled_at/ends_at bound the occupied slot (ends_at is
 * written from duration so overlap checks are a plain indexed range query). The
 * status machine lives in App\Enums\AppointmentStatus; every move is recorded in
 * appointment_status_histories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('scheduled_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('source', 16)->default('walk_in');
            $table->string('status', 16)->default('scheduled');
            $table->string('reason')->nullable();

            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Overlap queries filter by (hospital, doctor, time-range, status).
            $table->index(['hospital_id', 'doctor_user_id', 'scheduled_at'], 'appt_doctor_time');
            $table->index(['hospital_id', 'room_id', 'scheduled_at'], 'appt_room_time');
            $table->index(['hospital_id', 'status', 'scheduled_at'], 'appt_status_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
