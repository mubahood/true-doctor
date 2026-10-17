<?php

use App\Support\Diagnoses\Catalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dental case sheet (docs/dental.md, "The case sheet").
 *
 * `case_sheet_entries`: the history and examination of a visit, one row per
 * version of one section (presenting complaint, extra-oral examination...).
 * Append-only: a change is a new version, so what was written at each moment
 * is kept. The template names the practice's case sheet ("dental" today).
 *
 * `patient_history_entries`: what belongs to the patient rather than to one
 * visit (medical, surgical and financial history), versioned the same way.
 * A "Still correct" confirmation is a version too, marked `confirmed`.
 *
 * `dental_xrays`: an X-ray taken in the chair, on teeth, with its finding; the
 * order beside it carries its charge and its image.
 *
 * `patients.medical_flags`: the red flags ticked in the medical history (a
 * bleeding disorder, a heart valve...), kept on the patient so the alert bar
 * reads them without a query. It and the patient history's data are
 * encrypted at rest: they can say HIV, hepatitis or pregnancy.
 *
 * `visit_diagnoses.tooth` and `surfaces`: a coded diagnosis can name a tooth,
 * so caries on 35 and caries on 36 are two diagnoses of the same code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_sheet_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('template', 20);
            $table->string('section', 40);
            $table->unsignedInteger('version');
            $table->json('data');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['visit_id', 'section', 'version']);
            $table->index(['hospital_id', 'visit_id']);
        });

        Schema::create('patient_history_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section', 40);
            $table->unsignedInteger('version');
            $table->text('data');                       // encrypted: medicines, HIV or hepatitis, pregnancy
            $table->boolean('confirmed')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['patient_id', 'section', 'version']);
            $table->index(['hospital_id', 'patient_id']);
        });

        Schema::create('dental_xrays', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                 // App\Support\CaseSheet\DentalCaseSheet::XRAY_KINDS
            $table->json('teeth')->nullable();          // FDI numbers; null for the whole mouth (an OPG)
            $table->string('finding', 500);
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('taken_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'patient_id', 'taken_at']);
            $table->index(['hospital_id', 'visit_id']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->text('medical_flags')->nullable();   // encrypted, like patients.notes
        });

        Schema::table('visit_diagnoses', function (Blueprint $table) {
            $table->unsignedTinyInteger('tooth')->nullable();
            $table->string('surfaces', 6)->nullable();
        });

        // The dental codes the case sheet's diagnosis list maps to.
        Catalogue::syncShipped();
    }

    public function down(): void
    {
        Schema::table('visit_diagnoses', function (Blueprint $table) {
            $table->dropColumn(['tooth', 'surfaces']);
        });
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('medical_flags');
        });
        Schema::dropIfExists('dental_xrays');
        Schema::dropIfExists('patient_history_entries');
        Schema::dropIfExists('case_sheet_entries');
    }
};
