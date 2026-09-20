<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments of a hospital (OPD, Pharmacy, Laboratory, …). Tenant-scoped; the
 * head is an optional link to a staff user. Name and code are unique per hospital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 24)->nullable();
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hospital_id', 'name']);
            $table->unique(['hospital_id', 'code']);
            $table->index(['hospital_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
