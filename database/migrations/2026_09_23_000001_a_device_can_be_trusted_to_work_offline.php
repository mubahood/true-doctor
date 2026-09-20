<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which devices are allowed to hold patient data.
 *
 * Offline work means a copy of clinical records sitting in a browser on a
 * machine the hospital may or may not own. That is only acceptable if somebody
 * can see the list and take a device off it — so enabling offline mode is an
 * explicit, named, revocable act rather than a side effect of logging in
 * (plan §12).
 *
 * A revoked device's next sync is refused, and it wipes its local database on
 * being told so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Minted by the browser and never reused. Unique globally, not per
            // hospital: a device id that could mean two things is not an id.
            $table->uuid('device_uuid')->unique();

            // What a person would call it on a list: "Maternity desk laptop".
            $table->string('label', 120);
            $table->string('platform', 80)->nullable();

            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            // Revocation is a fact with a reason and an author, not a delete —
            // the audit trail has to survive the device being taken away.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 255)->nullable();

            // Where the device's pull has got to. Held server-side as well as
            // on the device so a wiped device can be told where it was.
            $table->string('pull_cursor', 255)->nullable();

            $table->unsignedInteger('protocol_version')->default(1);
            $table->timestamps();

            $table->index(['hospital_id', 'user_id']);
            $table->index(['hospital_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
