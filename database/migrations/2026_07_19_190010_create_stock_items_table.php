<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stocked item (drug / consumable). Quantities and money are decimal(12,2);
 * current_quantity and current_stock_value are maintained ONLY by StockService
 * inside transactions (never a model boot hook). batch/expiry drive alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('sku', 48)->nullable();
            $table->string('unit', 32)->default('units');
            $table->string('batch_no', 64)->nullable();
            $table->date('expiry_date')->nullable();

            $table->decimal('original_quantity', 12, 2)->default(0);
            $table->decimal('current_quantity', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->decimal('current_stock_value', 14, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'name', 'batch_no'], 'stock_item_name_batch');
            $table->index(['hospital_id', 'is_active']);
            $table->index(['hospital_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
