<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient invoice, usually generated from a visit's billable lines.
 * currency is snapshotted; all monetary columns are decimal(12,2) and are only
 * ever written by BillingService inside transactions. amount_paid/balance are
 * roll-ups kept in step with the append-only payments rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_no');
            // `consultations`, not `visits`: the hub is not renamed until
            // 2026_09_16_000002, and a foreign key can only point at a table
            // that exists NOW. `constrained()` would infer `visits` from the
            // column name and fail on MySQL with "Cannot add foreign key
            // constraint" — SQLite does not enforce it at create time, which
            // is why the test suite never saw this. RENAME TABLE carries the
            // key across.
            $table->foreignId('visit_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->string('currency', 5);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);

            $table->string('status', 16)->default('issued');
            $table->string('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'invoice_no']);
            $table->index(['hospital_id', 'status']);
            $table->index(['hospital_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
