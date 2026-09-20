<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that make incremental sync possible, added only where they earn
 * their place.
 *
 * `sync_revision` — a per-hospital monotonic counter stamped on every write.
 * The pull cursor is a revision, not a timestamp, and the difference matters:
 * `updated_at` collides at second resolution, and rows written inside one
 * transaction can carry timestamps that straddle another reader's cursor, so a
 * change is skipped and never sent again. A counter cannot do that
 * (plan §9.4).
 *
 * `version` — optimistic concurrency, added to the THREE tables the plan says
 * are both offline-editable and genuinely mutable (§10.1). Adding it to all 58
 * models would be the speculative scaffolding `decisions.md` warns against;
 * everything else offline is append-only and cannot conflict.
 *
 * Both are nullable and unused by the online path, so this migration is safe
 * to leave in place if offline mode is ever withdrawn (plan §21).
 *
 * SQLite note: adding a NULLABLE column with no constraint is a plain
 * `ALTER TABLE ADD COLUMN` on every engine — no table rebuild, so none of the
 * `SchemaKeys` cascade hazard applies here.
 */
return new class extends Migration
{
    /** Everything a device may pull. */
    private const REVISIONED = [
        'patients', 'visits', 'orders', 'order_items', 'appointments', 'admissions',
        'vital_rounds', 'nursing_notes', 'medication_administrations',
        'lab_orders', 'lab_order_items', 'radiology_orders', 'radiology_order_items',
    ];

    /** Offline-editable AND mutable. Everything else offline is append-only. */
    private const VERSIONED = ['patients', 'visits', 'lab_order_items'];

    public function up(): void
    {
        foreach (self::REVISIONED as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'sync_revision')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('sync_revision')->nullable()->index();
            });
        }

        foreach (self::VERSIONED as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'version')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                // Starts at 1, not 0: 0 is what a device uses to mean "this
                // record has never been to the server", and the two must not
                // be confusable.
                $t->unsignedInteger('version')->default(1);
            });
        }

        // Provenance for anything captured on a device. Kept on the tables
        // where an offline origin is possible, so a clinical record can always
        // answer "which device, which operation, and what time did the device
        // think it was" (plan §10.4, §17).
        foreach (['patients', 'visits', 'vital_rounds', 'nursing_notes', 'medication_administrations', 'lab_order_items'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'origin_device_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('origin_device_id')->nullable();
                $t->string('origin_operation_id', 40)->nullable()->index();
                // What the DEVICE thought the time was. The row's own
                // created_at stays the server's, which is authority.
                $t->timestamp('client_created_at')->nullable();
            });
        }

        // One counter per hospital, handed out under a row lock — the same
        // shape `Support\Sequence` already uses for invoice numbers, for the
        // same reason: two concurrent writers must never get the same value.
        Schema::create('sync_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_value')->default(0);
            $table->timestamps();

            $table->unique('hospital_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_revisions');

        foreach (self::REVISIONED as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'sync_revision')) {
                // The index comes off BEFORE the column. SQLite refuses to drop
                // a column an index still names — "1 error in index … after drop
                // column" — and this migration is rolled back and forward by
                // OrderMigrationTest on exactly that driver.
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table.'_sync_revision_index');
                    $t->dropColumn('sync_revision');
                });
            }
        }

        foreach (self::VERSIONED as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'version')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('version'));
            }
        }

        foreach (['patients', 'visits', 'vital_rounds', 'nursing_notes', 'medication_administrations', 'lab_order_items'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'origin_device_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table.'_origin_operation_id_index');
                    $t->dropColumn(['origin_device_id', 'origin_operation_id', 'client_created_at']);
                });
            }
        }
    }
};
