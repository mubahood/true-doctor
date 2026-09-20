<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only audit of every consultation state change. No updated_at. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'consultation_id', 'created_at'], 'consult_history_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_status_histories');
    }
};
