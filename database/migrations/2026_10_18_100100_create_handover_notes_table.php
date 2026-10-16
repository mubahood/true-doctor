<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A nursing shift handover: one row per ward and shift, the SBAR notes of every admission inside it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ward_id')->constrained()->cascadeOnDelete();
            $table->string('shift', 20);
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->json('notes');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'created_at']);
            $table->index(['ward_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_notes');
    }
};
