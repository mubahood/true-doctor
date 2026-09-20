<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One photo/step of a treatment record (private-disk image path + caption). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_record_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_record_id')->constrained()->cascadeOnDelete();

            $table->string('photo_path');
            $table->string('caption')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'treatment_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_record_items');
    }
};
