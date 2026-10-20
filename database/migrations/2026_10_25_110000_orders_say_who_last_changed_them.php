<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order can be changed after it was placed (its service, instructions,
 * clinician and charge). The audit trail holds every before and after; these
 * two columns let the order itself say "changed by" without reading it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // No database foreign key, on purpose: on SQLite adding one rebuilds
            // the table, and the rebuild cascades into order_items. The user
            // is read through Order::editor().
            $table->unsignedBigInteger('edited_by')->nullable()->after('requested_by');
            $table->timestamp('edited_at')->nullable()->after('edited_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['edited_by', 'edited_at']);
        });
    }
};
