<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid patient cards (HMS_PLAN.md §4). card_number is encrypted at rest
 * (C12); card_hash is a non-reversible lookup key so a card can still be found
 * and kept unique per hospital without storing the number in the clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->text('card_number');          // encrypted (Eloquent cast)
            $table->string('card_hash', 64);       // sha256 lookup key
            $table->date('expiry')->nullable();
            $table->string('status', 12)->default('active');
            $table->boolean('accepts_credit')->default(false);
            $table->decimal('max_credit', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'card_hash']);
            $table->index(['hospital_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_cards');
    }
};
