<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement (roadmap 04 §7): suppliers, purchase orders, goods received and
 * returns to supplier.
 *
 * Stock still moves only through StockService. A goods-received line and a
 * supplier return are the SOURCE of the stock movement they caused, so the
 * ledger can always say which delivery a unit came in on. Receipts and
 * returns are append-only (created_at only): a wrong receipt is put right with
 * a return, never by editing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('tin', 40)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'name']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('po_no', 40);
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('status', 20)->default('draft'); // App\Enums\PurchaseOrderStatus
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('ordered_at')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['hospital_id', 'po_no']);
            $table->index(['hospital_id', 'status']);
            $table->index(['hospital_id', 'updated_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->decimal('received_quantity', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('grn_no', 40);
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->date('received_on');
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['hospital_id', 'grn_no']);
            $table->index(['hospital_id', 'received_on']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained();
            $table->foreignId('stock_item_id')->constrained('stock_items');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('return_no', 40);
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('goods_receipt_id')->constrained();
            $table->foreignId('goods_receipt_item_id')->constrained();
            $table->foreignId('stock_item_id')->constrained('stock_items');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['hospital_id', 'return_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
