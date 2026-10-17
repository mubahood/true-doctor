<?php

use App\Support\Diagnoses\Catalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Coded diagnoses (roadmap 04 §1): the shared catalogue, and the append-only
 * record of the codes on each visit. The shipped list is loaded here so every
 * server has it from the first migrate; `diagnoses:catalogue` refreshes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_codes', function (Blueprint $t) {
            $t->id();
            $t->string('system', 10)->default('icd10');
            $t->string('code', 12);
            $t->string('title');
            $t->json('synonyms')->nullable();
            $t->text('search');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['system', 'code']);
        });

        Schema::create('visit_diagnoses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $t->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $t->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $t->string('system', 10)->default('icd10');
            $t->string('code', 12);
            $t->string('title');
            $t->boolean('is_primary')->default(false);
            $t->string('certainty', 12)->default('confirmed');
            $t->string('note', 500)->nullable();
            $t->foreignId('replaces_id')->nullable()->constrained('visit_diagnoses')->nullOnDelete();
            $t->boolean('removed')->default(false);
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('recorded_at');
            $t->timestamp('created_at')->nullable();
            $t->index(['hospital_id', 'visit_id']);
            $t->index(['hospital_id', 'code', 'recorded_at']);
        });

        Catalogue::syncShipped();
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_diagnoses');
        Schema::dropIfExists('diagnosis_codes');
    }
};
