<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient documents — reuses the credential-review pattern (HMS_PLAN.md §1).
 * Files live on the PRIVATE disk and are only ever streamed through an
 * authorized, tenant-scoped route; never publicly reachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);            // id | insurance | consent | referral | result | other
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime', 96)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
