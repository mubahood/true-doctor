<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit + idempotency ledger for gateway transactions (HMS_PLAN.md §16). One row
 * per initialized payment, keyed by our tx_ref (unique). status advances
 * pending → successful/failed only after a server-side verify; a successful row
 * is the guard against double-crediting an invoice (webhook + redirect both hit
 * the same settle()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 24)->default('flutterwave');
            $table->string('tx_ref')->unique();          // our reference
            $table->string('provider_ref')->nullable();  // gateway's transaction id
            $table->string('status', 16)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 5);
            $table->string('payment_type', 32)->nullable();
            $table->boolean('verified')->default(false);
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['hospital_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_logs');
    }
};
