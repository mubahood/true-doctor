<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only payment against an invoice. A card payment also links to the
 * card ledger row it created (card_record_id), so the two money trails reconcile.
 * balance_after snapshots the invoice balance immediately after this payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->string('method', 16);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('patient_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('card_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'invoice_id', 'created_at'], 'payment_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
