<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's weekly availability template (HMS_PLAN.md §4, B7). A doctor may have
 * several windows per weekday. Booking validates the requested time against an
 * active window here; concurrent bookings serialize on these rows (lockForUpdate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();      // the doctor
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('weekday');   // 0=Sun … 6=Sat (Carbon dayOfWeek)
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['hospital_id', 'user_id', 'weekday', 'is_active'], 'doctor_schedule_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};
