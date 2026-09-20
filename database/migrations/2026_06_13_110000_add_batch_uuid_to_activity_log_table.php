<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity_log table was first created from the activitylog v5 stub (which
 * omits batch_uuid). After pinning to PHP 8.2 we use activitylog v4, which
 * expects a batch_uuid column. Add it idempotently.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activity_log', 'batch_uuid')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->uuid('batch_uuid')->nullable()->after('properties');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activity_log', 'batch_uuid')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropColumn('batch_uuid');
            });
        }
    }
};
