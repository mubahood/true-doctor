<?php

use App\Support\SchemaKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount is decided before the invoice, so it lives on the visit.
 *
 * It was only ever a parameter to `generateInvoice()` — typed into the
 * generate dialog and gone if you closed it. That is the wrong moment: a
 * discount is agreed at the counter while the bill is being read, and whoever
 * agreed it is not always whoever raises the invoice.
 *
 * So it is stored, shown in the totals as it accrues, and carried onto the
 * invoice when one is raised. And because giving money away is the kind of
 * thing an audit asks about, the reason and the hand are stored with it.
 *
 * WHY `discounted_by` CARRIES NO FOREIGN KEY ON SQLITE
 *
 * Adding a constraint to a live table makes SQLite rebuild it, and the rebuild
 * cascades. `App\Support\SchemaKeys` holds the whole explanation and is the
 * one place that decides; every migration that alters a table to add a key
 * goes through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            if (! Schema::hasColumn('visits', 'discount')) {
                $table->decimal('discount', 14, 2)->default(0)->after('status');
            }
            if (! Schema::hasColumn('visits', 'discount_reason')) {
                $table->string('discount_reason')->nullable()->after('discount');
            }
            if (! Schema::hasColumn('visits', 'discounted_by')) {
                $table->unsignedBigInteger('discounted_by')->nullable()->after('discount_reason');
            }
        });

        SchemaKeys::add('visits', 'discounted_by', 'users');
    }

    public function down(): void
    {
        SchemaKeys::drop('visits', 'discounted_by');

        Schema::table('visits', function (Blueprint $table) {
            foreach (['discounted_by', 'discount_reason', 'discount'] as $column) {
                if (Schema::hasColumn('visits', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
