<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maternity and child health (roadmap 04 §4, docs/mother-child.md).
 *
 * `pregnancies`: one per pregnancy, with the dating (LMP, EDD), gravida and
 * para, risk factors and the birth plan. At most one is open per woman
 * (PregnancyService holds that under a lock). Edits are logged.
 *
 * `anc_contacts`: append-only, one row per antenatal contact with what was
 * measured and the checklist of what was done.
 *
 * `immunisations`: append-only, one row per dose given (here or, from the
 * child health card, elsewhere). A wrong entry is voided with a reason, never
 * deleted or edited, so the register can be audited.
 *
 * `growth_measurements`: append-only weight, length/height and MUAC taken at
 * a child clinic. The growth chart also reads the weights and heights taken
 * as vitals on visits, so nothing is typed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pregnancies', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('lmp')->nullable();
            $table->date('edd');
            $table->boolean('edd_from_lmp')->default(true);
            $table->unsignedTinyInteger('gravida');
            $table->unsignedTinyInteger('para')->default(0);
            $table->json('risk_factors')->nullable();          // keys from config('mother_child.anc.risk_factors')
            $table->string('risk_notes', 500)->nullable();
            $table->string('birth_place', 120)->nullable();    // the birth plan
            $table->string('birth_companion', 120)->nullable();
            $table->string('transport', 120)->nullable();
            $table->string('blood_donor', 120)->nullable();
            $table->string('birth_plan_notes', 500)->nullable();
            $table->string('status', 16)->default('active');   // App\Enums\PregnancyStatus
            $table->date('ended_on')->nullable();
            $table->string('outcome_notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'patient_id', 'status']);
            $table->index(['hospital_id', 'status', 'edd']);
        });

        Schema::create('anc_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pregnancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->date('seen_on');
            $table->unsignedSmallInteger('gestation_days');     // on seen_on, from the EDD
            $table->unsignedTinyInteger('contact_no');          // the scheduled contact it counts against
            $table->string('blood_pressure', 12)->nullable();
            $table->decimal('weight', 5, 2)->nullable();        // kg
            $table->unsignedTinyInteger('fundal_height')->nullable(); // cm
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable();
            $table->json('checks')->nullable();                 // keys from config('mother_child.anc.checks')
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'pregnancy_id', 'seen_on']);
        });

        Schema::create('immunisations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('dose_key', 20);                     // key in config('mother_child.immunisation.schedule')
            $table->date('given_on');
            $table->boolean('given_elsewhere')->default(false); // copied from the child health card
            $table->string('batch_no', 40)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->index(['hospital_id', 'patient_id', 'dose_key']);
            $table->index(['hospital_id', 'given_on']);
        });

        Schema::create('growth_measurements', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->date('measured_on');
            $table->decimal('weight', 5, 2)->nullable();        // kg
            $table->decimal('height', 5, 1)->nullable();        // cm, lying under two years
            $table->decimal('muac', 4, 1)->nullable();          // cm
            $table->boolean('oedema')->default(false);
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'patient_id', 'measured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_measurements');
        Schema::dropIfExists('immunisations');
        Schema::dropIfExists('anc_contacts');
        Schema::dropIfExists('pregnancies');
    }
};
