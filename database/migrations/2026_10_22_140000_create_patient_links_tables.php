<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient links and document verification (roadmap 05 §4):
 *
 *  - patient_links: one short link a patient opens without an account, to one
 *    document (a result, a discharge summary, a prescription, a quote). Only
 *    hashes of the token and the unlock code are kept. It expires, can be
 *    revoked, and locks after five wrong codes;
 *  - patient_link_opens: every attempt to open a link, append-only, so the
 *    clinic can see who opened it and from where;
 *  - document_verifications: one row per printed document per day, holding
 *    the short code its QR carries and only what the public page may say
 *    (what it is, its number, the date, the patient's initials).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('patient_links')) {
            Schema::create('patient_links', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
                $table->string('document_type', 32);
                $table->unsignedBigInteger('document_id');
                $table->string('title', 120);
                $table->string('reference', 60)->nullable();
                $table->string('token_hash', 64)->unique();
                $table->string('code_hash', 255);
                $table->unsignedTinyInteger('failed_attempts')->default(0);
                $table->dateTime('locked_at')->nullable();
                $table->dateTime('expires_at');
                $table->dateTime('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('patient_message_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('opens_count')->default(0);
                $table->dateTime('last_opened_at')->nullable();
                $table->timestamps();
                $table->index(['hospital_id', 'created_at']);
                $table->index(['hospital_id', 'document_type', 'document_id']);
                $table->index(['patient_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('patient_link_opens')) {
            Schema::create('patient_link_opens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('patient_link_id')->constrained()->cascadeOnDelete();
                $table->string('event', 16);
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index(['patient_link_id', 'id']);
            });
        }

        if (! Schema::hasTable('document_verifications')) {
            Schema::create('document_verifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->string('code', 12)->unique();
                $table->string('fingerprint', 64);
                $table->string('title', 120);
                $table->string('reference', 120)->nullable();
                $table->string('patient_initials', 8)->nullable();
                $table->dateTime('issued_at');
                $table->unsignedInteger('checks_count')->default(0);
                $table->dateTime('last_checked_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->unique(['hospital_id', 'fingerprint']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
        Schema::dropIfExists('patient_link_opens');
        Schema::dropIfExists('patient_links');
    }
};
