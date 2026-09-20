<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One card, several people — see docs/cards.md.
 *
 * `patient_cards.patient_id` stays exactly what it is: the PRIMARY HOLDER, the
 * person the card was issued to, and it never changes. This table adds everyone
 * else who may spend on it — a spouse, the children, an employer's staff.
 *
 * Until now `BillingService::recordPayment` refused any card whose patient did
 * not match the invoice's, which is precisely the case a family card exists
 * for: a mother's card could not pay for her child.
 *
 * A holder is REVOKED, never deleted. What they spent stays on the ledger, and
 * a row that disappears takes the explanation of an old charge with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('card_holders')) {
            return;
        }

        Schema::create('card_holders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->string('relationship', 16)->default('other');
            $table->string('status', 12)->default('active');

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();

            $table->timestamps();

            // One row per person per card: re-adding somebody revoked flips the
            // row back rather than stacking a second one beside it.
            $table->unique(['patient_card_id', 'patient_id'], 'card_holder_unique');
            $table->index(['hospital_id', 'patient_id', 'status'], 'card_holder_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_holders');
    }
};
