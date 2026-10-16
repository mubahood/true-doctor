<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tombstones: what a device must forget.
 *
 * A soft delete is already visible to the pull stream for patients, but a row
 * that merely LEAVES a list - a lab order closed or cancelled out of the
 * worklist, a record deleted from a table the stream does not carry - leaves no
 * trace, and the device holds it for ever. One row here is written by a model
 * observer when that happens, with a revision from the hospital's own counter
 * (SyncRevision) so pull and bootstrap can serve it in order.
 *
 * `uuid` holds the record's uuid, or its numeric id as a string for a
 * catalogue row that has none (services). `reason` is `deleted` or `closed`
 * (closed = left the worklist, the record still exists).
 *
 * Pruned after 90 days by the scheduler. A device that has been away longer is
 * told to rebuild by its own cursor rules, not by this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_tombstones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 40);
            $table->string('uuid', 64);
            $table->unsignedBigInteger('revision');
            $table->string('reason', 16)->default('deleted');
            $table->timestamp('deleted_at')->useCurrent();

            $table->index(['hospital_id', 'revision'], 'sync_tombstones_hospital_revision');
            $table->index(['hospital_id', 'entity', 'uuid'], 'sync_tombstones_entity_uuid');
            $table->index('deleted_at', 'sync_tombstones_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tombstones');
    }
};
