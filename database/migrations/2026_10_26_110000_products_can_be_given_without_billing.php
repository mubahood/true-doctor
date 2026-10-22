<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product can be used on a visit without being billed (docs/orders.md,
 * "Billed or not billed"). The stock still leaves the shelf and its cost still
 * reaches the books; only the patient's bill leaves it out.
 *
 * - `billed`: false keeps the line visible on its order with no amount.
 * - `unit_cost`: what one cost when it was used, so "used but not billed"
 *   can be valued at cost without reading the ledger.
 * - `billing_set_by` / `billing_set_at`: who chose, and when.
 *
 * Every existing line stays billed, so no visit's total moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // No database foreign key, on purpose: on SQLite adding one rebuilds
            // the table. The user is read through OrderItem::billingSetBy().
            $table->boolean('billed')->default(true)->after('tax_exempt');
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
            $table->unsignedBigInteger('billing_set_by')->nullable()->after('billed');
            $table->timestamp('billing_set_at')->nullable()->after('billing_set_by');
            $table->index(['hospital_id', 'billed'], 'order_items_hospital_billed_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_hospital_billed_index');
            $table->dropColumn(['billed', 'unit_cost', 'billing_set_by', 'billing_set_at']);
        });
    }
};
