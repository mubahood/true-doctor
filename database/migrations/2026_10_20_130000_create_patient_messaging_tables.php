<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages to patients (roadmap 05 §1, §2):
 *
 *  - patient_consents: each answer about being messaged, per channel and
 *    purpose, append-only (the latest is in force);
 *  - patient_messages: every message, once per event (unique dedupe key per
 *    hospital), with what the gateway said;
 *  - message_credits: the message balance ledger, only ever posted to.
 *
 * Nothing is inferred for patients already registered: `consent_given` was
 * consent to keep their records, not to text them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('patient_consents')) {
            Schema::create('patient_consents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
                $table->string('channel', 16);
                $table->string('purpose', 16);
                $table->boolean('granted');
                $table->string('method', 16)->default('desk');
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->dateTime('recorded_at');
                $table->dateTime('created_at')->nullable();
                $table->index(['patient_id', 'channel', 'purpose', 'recorded_at'], 'patient_consents_lookup');
            });
        }

        if (! Schema::hasTable('patient_messages')) {
            Schema::create('patient_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
                $table->string('channel', 16);
                $table->string('purpose', 16);
                $table->string('event', 48);
                $table->string('dedupe_key', 191);
                $table->string('to_phone', 20)->nullable();
                $table->text('body');
                $table->string('status', 16)->index();
                $table->string('skip_reason', 32)->nullable();
                $table->string('provider', 32)->nullable();
                $table->string('provider_id', 100)->nullable()->index();
                $table->unsignedSmallInteger('segments')->default(1);
                $table->string('cost', 32)->nullable();
                $table->string('error', 255)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->dateTime('send_after')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->timestamps();
                $table->unique(['hospital_id', 'dedupe_key']);
                $table->index(['hospital_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('message_credits')) {
            Schema::create('message_credits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->string('entry', 16);
                $table->integer('messages');
                $table->integer('balance_after');
                $table->string('reference', 100)->nullable();
                $table->foreignId('patient_message_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index(['hospital_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_credits');
        Schema::dropIfExists('patient_messages');
        Schema::dropIfExists('patient_consents');
    }
};
