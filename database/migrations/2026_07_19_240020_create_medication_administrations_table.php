<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Medication administration record (MAR) entry for an inpatient (append-only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_administrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();

            $table->string('drug_name');
            $table->string('dose')->nullable();
            $table->string('route', 32)->nullable();       // oral, IV, IM, …
            $table->string('status', 12)->default('given');
            $table->string('note')->nullable();
            $table->foreignId('administered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'admission_id', 'created_at'], 'mar_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_administrations');
    }
};
