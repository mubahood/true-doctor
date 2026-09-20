<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only credit/debit ledger for patient cards (constraint F — money
 * moves through immutable ledger rows inside transactions, never by editing a
 * balance in place). balance_after snapshots the running balance per entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_records', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->string('type', 8);             // credit | debit
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('description')->nullable();
            $table->string('reference')->nullable(); // future: invoice/visit link
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'patient_card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_records');
    }
};
