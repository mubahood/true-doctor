<?php

use App\Support\SchemaKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The insurer's side of the card (docs/cards.md).
 *
 * An insurer deposits money with the hospital; that money clears its members'
 * negative cards. Both halves are ledgers and both are append-only, so a
 * settlement writes two rows in one transaction and links them to each other:
 * a `settlement` here that takes money off the float, and a `credit` on
 * `card_records` that puts it onto the card. Either both land or neither does.
 *
 * `insurance_providers.float_balance` is a cache of this ledger, moved only
 * inside InsuranceLedgerService under a lock on the provider row, exactly as
 * `patient_cards.balance` is a cache of `card_records`.
 *
 * Claims (`insurance_claims`) are a different thing and stay as they are: a
 * claim settles ONE INVOICE for one patient. This is the other arrangement —
 * the insurer funds a card, the patient spends against it, and the hospital
 * bills the insurer for the usage afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('insurance_providers', 'float_balance')) {
                $table->decimal('float_balance', 14, 2)->default(0)->after('is_active');
            }
            // What a card issued against this insurer starts life with, so a
            // clerk is not asked to remember each company's terms.
            if (! Schema::hasColumn('insurance_providers', 'default_credit_limit')) {
                $table->decimal('default_credit_limit', 14, 2)->default(0)->after('float_balance');
            }
        });

        if (! Schema::hasTable('insurance_transactions')) {
            Schema::create('insurance_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('insurance_provider_id')->constrained()->cascadeOnDelete();

                $table->string('type', 16);              // deposit | settlement | adjustment
                $table->decimal('amount', 14, 2);        // signed only for an adjustment
                $table->decimal('balance_after', 14, 2);

                // A settlement names the card it paid; a deposit names none.
                $table->foreignId('patient_card_id')->nullable()->constrained()->nullOnDelete();

                $table->string('reference')->nullable();  // the bank's, not ours
                $table->string('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['hospital_id', 'insurance_provider_id', 'created_at'], 'ins_txn_ledger');
                $table->index(['hospital_id', 'patient_card_id'], 'ins_txn_card');
            });
        }

        // The other end of the link, so a card's credit can be traced back to
        // the deposit that paid for it.
        Schema::table('card_records', function (Blueprint $table) {
            if (! Schema::hasColumn('card_records', 'insurance_transaction_id')) {
                $table->unsignedBigInteger('insurance_transaction_id')->nullable()->after('reference');
            }
            // Who the money was spent ON. The column is older than this and has
            // until now been a copy of the card's owner, which says nothing;
            // from here it is the member, which is what a usage report is made
            // of. Every existing row is already the owner's own spending, so
            // there is nothing to backfill.
        });

        SchemaKeys::add('card_records', 'insurance_transaction_id', 'insurance_transactions');
    }

    public function down(): void
    {
        SchemaKeys::drop('card_records', 'insurance_transaction_id');

        Schema::table('card_records', function (Blueprint $table) {
            if (Schema::hasColumn('card_records', 'insurance_transaction_id')) {
                $table->dropColumn('insurance_transaction_id');
            }
        });

        Schema::dropIfExists('insurance_transactions');

        Schema::table('insurance_providers', function (Blueprint $table) {
            foreach (['default_credit_limit', 'float_balance'] as $column) {
                if (Schema::hasColumn('insurance_providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
