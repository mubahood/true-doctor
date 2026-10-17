<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Refunds and credit notes (roadmap 04 §8, docs/refunds.md).
 *
 * One append-only table for both documents: a credit note takes money off
 * what an invoice asks for; a refund pays money back out. Each is asked for,
 * then approved (numbered, posted to the books) or rejected, once. The
 * invoice keeps running totals of both beside `amount_paid`, so its balance
 * stays one column every report already reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_credits', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 12);                      // credit_note | refund
            $t->string('number', 30)->nullable();        // given on approval: CN-2026-00001 / RF-2026-00001
            $t->string('status', 12)->default('pending'); // pending | approved | rejected
            $t->decimal('amount', 12, 2);
            $t->decimal('tax_part', 12, 2)->default(0);  // the tax a credit note gives back
            $t->string('method', 20)->nullable();        // refund: how the money went back
            $t->string('reference', 120)->nullable();
            $t->foreignId('patient_card_id')->nullable()->constrained('patient_cards')->nullOnDelete();
            $t->foreignId('card_record_id')->nullable()->constrained('card_records')->nullOnDelete();
            $t->string('reason', 500);
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at');
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamps();

            $t->unique(['hospital_id', 'number']);
            $t->index(['hospital_id', 'status']);
            $t->index(['hospital_id', 'decided_at']);
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('credited', 12, 2)->default(0)->after('amount_paid');
            $t->decimal('refunded', 12, 2)->default(0)->after('credited');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['credited', 'refunded']);
        });
        Schema::dropIfExists('invoice_credits');
    }
};
