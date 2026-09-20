<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock groupings with a default measuring unit (tablets, ml, bottles, …). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 32)->default('units');   // default measuring unit
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_categories');
    }
};
