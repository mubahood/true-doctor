<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms / visit spaces / wards of a hospital. Optionally attached to a
 * department. Type + status are backed enums; name is unique per hospital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('type', 24)->default('consultation');
            $table->string('status', 24)->default('available');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'name']);
            $table->index(['hospital_id', 'department_id']);
            $table->index(['hospital_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
