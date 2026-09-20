<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only record of a bed move within an admission. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bed_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->foreignId('to_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hospital_id', 'admission_id', 'created_at'], 'bed_transfer_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_transfers');
    }
};
