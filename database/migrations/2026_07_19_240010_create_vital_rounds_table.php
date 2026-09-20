<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A set of vitals taken during an inpatient round (append-only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vital_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();

            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->string('blood_pressure', 12)->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedSmallInteger('spo2')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'admission_id', 'created_at'], 'vital_round_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vital_rounds');
    }
};
