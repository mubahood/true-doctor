<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only in/out ledger for one stock item. quantity is a positive
 * magnitude; the reason's sign decides direction. balance_after snapshots
 * current_quantity right after the movement — the ledger is the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();

            $table->string('reason', 24);
            $table->decimal('quantity', 12, 2);           // positive magnitude
            $table->decimal('balance_after', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->nullableMorphs('source');             // e.g. dispensation
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'stock_item_id', 'created_at'], 'stock_movement_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
