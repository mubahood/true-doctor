<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dental visit's simple notes (docs/dental.md, "Simple notes"): History
 * (medical, family, dental, surgical) and Oral examination (hard tissues,
 * soft tissues, X-ray, diagnosis) as plain text, one row per version.
 *
 * Append-only like the case sheet: a change is a new version, so what was
 * written at each moment is kept. The text is encrypted at rest: a medical
 * history can say HIV, hepatitis or pregnancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('data');                       // encrypted: the eight notes
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['visit_id', 'version']);
            $table->index(['hospital_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dental_notes');
    }
};
