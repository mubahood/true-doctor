<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A charge can now be changed, so it has to say who changed it.
 *
 * `ordered_by` records who put the line on. That was enough while a line was
 * write-once; now that a quantity or a note can be corrected — and a quantity
 * on a product moves stock when it is — the last hand on it matters as much as
 * the first. `updated_at` already says when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_items', 'updated_by')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('ordered_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('order_items', 'updated_by')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
