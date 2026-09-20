<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One study on a radiology order; name/modality/price snapshotted at order time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('radiology_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('radiology_study_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('modality', 32)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['hospital_id', 'radiology_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_order_items');
    }
};
