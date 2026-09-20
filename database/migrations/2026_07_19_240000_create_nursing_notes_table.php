<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only nursing notes on an admission. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nursing_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'admission_id', 'created_at'], 'nursing_note_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nursing_notes');
    }
};
