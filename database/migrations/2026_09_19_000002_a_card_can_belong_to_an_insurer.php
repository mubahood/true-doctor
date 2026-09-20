<?php

use App\Support\SchemaKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A card may be an insurer's rather than the patient's own (docs/cards.md).
 *
 * The difference is whose debt it is. An ordinary prepaid card is spent down to
 * nothing and topped up by the patient. An insurance card is expected to run
 * NEGATIVE — that is the whole point of it — and the company behind it clears
 * what it owes, in bulk, against a float it deposits with the hospital.
 *
 * `member_no` is the number the insurer knows the holder by, which is what
 * appears on the usage report the hospital hands back to them. It is kept on
 * the card rather than looked up through `patient_insurances` because a family
 * card has several patients on it and one membership.
 *
 * The constraint goes on through SchemaKeys: `patient_cards` is referenced by
 * `card_records` and by `payments`, so a rebuild to add a key in place would
 * cascade through both ledgers. See that class for the whole story.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_cards', function (Blueprint $table) {
            if (! Schema::hasColumn('patient_cards', 'insurance_provider_id')) {
                $table->unsignedBigInteger('insurance_provider_id')->nullable()->after('patient_id');
            }
            if (! Schema::hasColumn('patient_cards', 'member_no')) {
                $table->string('member_no', 64)->nullable()->after('insurance_provider_id');
            }
        });

        // "Which cards are this insurer's, and which of them are in debt."
        if (! $this->hasIndex('patient_cards', 'card_insurer_idx')) {
            Schema::table('patient_cards', fn (Blueprint $table) => $table
                ->index(['hospital_id', 'insurance_provider_id'], 'card_insurer_idx'));
        }

        SchemaKeys::add('patient_cards', 'insurance_provider_id', 'insurance_providers');
    }

    public function down(): void
    {
        SchemaKeys::drop('patient_cards', 'insurance_provider_id');

        if ($this->hasIndex('patient_cards', 'card_insurer_idx')) {
            Schema::table('patient_cards', fn (Blueprint $table) => $table->dropIndex('card_insurer_idx'));
        }

        Schema::table('patient_cards', function (Blueprint $table) {
            foreach (['member_no', 'insurance_provider_id'] as $column) {
                if (Schema::hasColumn('patient_cards', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
