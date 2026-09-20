<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One test on a lab order, with its result inline (value/flag/notes) — a 1:1
 * result per ordered test. name/price/reference_range are snapshots at order time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');                       // snapshot
            $table->string('unit', 32)->nullable();
            $table->string('reference_range')->nullable();
            $table->decimal('price', 12, 2)->default(0);

            $table->string('result_value')->nullable();
            $table->string('result_flag', 12)->nullable();
            $table->string('result_notes')->nullable();
            $table->dateTime('resulted_at')->nullable();
            $table->foreignId('resulted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'lab_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_items');
    }
};
