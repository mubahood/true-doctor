<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount is either a number or a rule, and the two behave differently.
 *
 * `visits.discount` held resolved money, which is right for a fixed amount and
 * wrong for a percentage: ten per cent of a bill that grows is not the figure
 * it was when it was agreed. So the column now holds what was ENTERED, and the
 * type says how to read it — the money is worked out against the bill of the
 * moment, every time it is asked for.
 *
 * Nothing carries a discount yet, so the rename loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            if (Schema::hasColumn('visits', 'discount') && ! Schema::hasColumn('visits', 'discount_value')) {
                $table->renameColumn('discount', 'discount_value');
            }
        });

        Schema::table('visits', function (Blueprint $table) {
            if (! Schema::hasColumn('visits', 'discount_type')) {
                $table->string('discount_type', 12)->default('amount')->after('discount_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            if (Schema::hasColumn('visits', 'discount_type')) {
                $table->dropColumn('discount_type');
            }
        });

        Schema::table('visits', function (Blueprint $table) {
            if (Schema::hasColumn('visits', 'discount_value') && ! Schema::hasColumn('visits', 'discount')) {
                $table->renameColumn('discount_value', 'discount');
            }
        });
    }
};
