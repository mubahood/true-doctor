<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One prescribed drug line: what, how much, which daily slots, for how many days.
 * DosageScheduleGenerator expands (slots × days) into dose_item_records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dose_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();

            $table->string('drug_name');
            $table->string('dosage')->nullable();          // e.g. "500mg", "1 tab"
            $table->json('slots');                          // ["morning","night"]
            $table->unsignedSmallInteger('days');
            $table->date('start_date');
            $table->string('instructions')->nullable();     // e.g. "after meals"
            $table->timestamps();

            $table->index(['hospital_id', 'prescription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dose_items');
    }
};
