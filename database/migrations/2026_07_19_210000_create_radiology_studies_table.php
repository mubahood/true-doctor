<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Radiology study catalogue (X-ray chest, CT head, …). Prices in hospital currency. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_studies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('modality', 32)->nullable();   // X-ray, CT, MRI, Ultrasound
            $table->string('body_part', 64)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_studies');
    }
};
