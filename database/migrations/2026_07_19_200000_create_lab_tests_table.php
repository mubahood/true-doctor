<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The lab test catalogue / price list. Prices in the hospital's currency. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->string('specimen', 64)->nullable();       // blood, urine, …
            $table->string('unit', 32)->nullable();           // result unit
            $table->string('reference_range')->nullable();    // e.g. "4.0–11.0"
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_tests');
    }
};
