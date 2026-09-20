<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One drug line of a dispensation; drives a Dispensed stock movement + a bill line. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispensation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();

            $table->string('name');                        // snapshot
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);          // snapshot of sale_price
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['hospital_id', 'dispensation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensation_items');
    }
};
