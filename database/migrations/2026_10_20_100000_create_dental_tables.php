<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dental chart and the treatment plan (roadmap 04 §3).
 *
 * `dental_chart_entries` is append-only: a finding on a tooth (and surfaces)
 * at a moment, by a person. The chart on any date is the latest entry per
 * tooth and surface up to that date, so nothing is ever overwritten and the
 * chart of last March can still be shown.
 *
 * `dental_treatment_items` are the procedures planned per tooth from the
 * clinic's own price list, moving planned → accepted → in progress → done.
 * Done means billed: the item points at the order line that charged it, so a
 * procedure is never billed twice and never forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_chart_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->unsignedTinyInteger('tooth');              // FDI: 11-48 adult, 51-85 child
            $table->string('surfaces', 6)->nullable();         // M O D B L I, as letters; null = the whole tooth
            $table->string('finding', 20);                     // App\Enums\DentalFinding
            $table->unsignedTinyInteger('mobility')->nullable(); // grade 0-3 when the finding is mobility
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->nullable();
            // Offline provenance, as on every append-only clinical row (OFFLINE_SYNC_PROTOCOL).
            $table->unsignedBigInteger('sync_revision')->nullable()->index();
            $table->unsignedBigInteger('origin_device_id')->nullable();
            $table->string('origin_operation_id', 64)->nullable();
            $table->dateTime('client_created_at')->nullable();

            $table->index(['hospital_id', 'patient_id', 'recorded_at']);
        });

        Schema::create('dental_treatment_items', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();      // where it was planned
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedTinyInteger('tooth')->nullable();
            $table->string('surfaces', 6)->nullable();
            $table->decimal('price', 14, 2);
            $table->string('status', 16)->default('planned');  // App\Enums\DentalTreatmentStatus
            $table->string('notes', 500)->nullable();
            $table->foreignId('planned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('done_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'patient_id', 'status']);
            $table->index(['hospital_id', 'done_at']);
        });

        Schema::table('patients', function (Blueprint $table) {
            // The next check-up a dental clinic expects (default six months);
            // feeds the recall message (roadmap 05).
            $table->date('next_recall_on')->nullable();
            $table->index(['hospital_id', 'next_recall_on']);
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['hospital_id', 'next_recall_on']);
            $table->dropColumn('next_recall_on');
        });
        Schema::dropIfExists('dental_treatment_items');
        Schema::dropIfExists('dental_chart_entries');
    }
};
