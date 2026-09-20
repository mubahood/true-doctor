<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stage change that broke the pipeline's rules says so.
 *
 * The visit machine is forward-only, and that is right: it is what stops a
 * half-billed visit sliding back to triage by accident. But a machine with no
 * escape hatch gets one anyway — someone edits the database, or the visit is
 * abandoned and a duplicate opened beside it. Better to have the hatch, and to
 * make every use of it obvious.
 *
 * The flag is on the audit row rather than the visit because it is a fact
 * about one change, not about the visit; the trail shows which moves were the
 * pipeline and which were somebody overruling it.
 */
return new class extends Migration
{
    private const TABLE = 'visit_status_histories';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'is_override')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->boolean('is_override')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'is_override')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn('is_override'));
        }
    }
};
