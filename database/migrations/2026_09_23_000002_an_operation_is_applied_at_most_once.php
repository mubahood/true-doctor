<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency ledger — the single most important table in offline sync.
 *
 * A network can fail after the server has committed and before the browser
 * hears about it. The device cannot tell that from "the request never
 * arrived", so it retries, and without this table the retry creates a second
 * patient, a second dispensing, a second payment.
 *
 * The guarantee is the UNIQUE INDEX on `operation_id`, not application logic.
 * Two concurrent replays race to insert; one wins, the other gets a constraint
 * violation and reads back the stored result. Checking "does a row exist" in
 * PHP and then inserting would leave exactly the window the constraint closes
 * (plan §9.3, invariants I-1 and I-2).
 *
 * `result` holds what the first application produced, so a replay returns the
 * same answer rather than a vague "already done" the client cannot act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();

            // ULID from the device. Unique across the whole installation —
            // scoping it per hospital would let a compromised device replay
            // another tenant's operation id and learn whether it existed.
            $table->string('operation_id', 40)->unique();

            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('sync_session_id')->nullable();

            $table->string('entity', 60);
            $table->uuid('entity_uuid');
            $table->string('operation', 20);           // create · update · delete · intent

            $table->string('status', 20);              // processing · accepted · rejected · conflict · failed
            $table->string('reason_code', 60)->nullable();
            $table->text('message')->nullable();

            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->unsignedInteger('base_version')->nullable();

            // What the first application produced, replayed verbatim.
            $table->json('result')->nullable();

            // A HASH of the payload, never the payload. A clinical record
            // sitting in a log table is a second copy nobody governs; the hash
            // is enough to prove two submissions carried the same content
            // (plan §20).
            $table->string('payload_hash', 64)->nullable();

            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'entity', 'entity_uuid']);
            $table->index(['hospital_id', 'status']);
            $table->index('created_at');               // pruning at 90 days
        });

        Schema::create('sync_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();

            $table->string('kind', 20);                // push · pull · bootstrap
            $table->unsignedInteger('operation_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('client_protocol', 10)->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'created_at']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('operation_id', 40);
            $table->string('entity', 60);
            $table->uuid('entity_uuid');
            $table->string('strategy', 30);            // field_merge · server_wins · manual · …

            $table->unsignedInteger('base_version')->nullable();
            $table->unsignedInteger('server_version')->nullable();

            // The field NAMES in dispute, not their values — enough for a
            // person to see what needs a decision without filing the clinical
            // content in a second place.
            $table->json('contested_fields')->nullable();
            $table->json('merged_fields')->nullable();

            $table->string('resolution', 30)->nullable(); // auto_merged · kept_server · kept_client
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'resolved_at']);
            $table->index(['entity', 'entity_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_sessions');
        Schema::dropIfExists('sync_operations');
    }
};
