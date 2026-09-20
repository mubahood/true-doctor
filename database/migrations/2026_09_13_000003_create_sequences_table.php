<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-tenant document number counters (App\Support\Sequence). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hospital_id')->nullable();
            $table->string('key', 32);
            $table->string('period', 16);
            $table->unsignedBigInteger('next_value')->default(0);
            $table->timestamps();

            $table->unique(['hospital_id', 'key', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
