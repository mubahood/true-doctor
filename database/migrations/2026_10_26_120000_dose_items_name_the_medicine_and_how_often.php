<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dosage on a visit (docs/pharmacy.md, "Dosage"): a prescription line can
 * name the medicine picked from the clinic's own stock, say how often in words
 * ("Twice a day", "When needed"), and be addressed by the app through a uuid.
 * Older lines keep their typed drug name and daily slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dose_items', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            // No database foreign key, on purpose: on SQLite adding one rebuilds
            // the table, and the rebuild cascades into dose_item_records. The
            // stock item is read through DoseItem::stockItem().
            $table->unsignedBigInteger('stock_item_id')->nullable()->after('prescription_id');
            $table->string('frequency', 60)->nullable()->after('dosage');

            $table->index('uuid');
            $table->index('stock_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('dose_items', function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->dropIndex(['stock_item_id']);
            $table->dropColumn(['uuid', 'stock_item_id', 'frequency']);
        });
    }
};
