<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of every appointment state change (constraint: explicit
 * state machines ship with a history table). No updated_at — rows never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 16)->nullable();  // null = initial booking
            $table->string('to_status', 16);
            $table->string('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'appointment_id', 'created_at'], 'appt_history_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_status_histories');
    }
};
