<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * appointments:remind now runs hourly (a shared host's cron may be late, so
 * "once a day at 07:00" could be missed entirely). Each reminder is stamped,
 * so an hourly run, a late run and a re-run never send twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();       // the day-before reminder
            $table->timestamp('reminder_soon_sent_at')->nullable();  // the two-hours-before reminder
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['reminder_sent_at', 'reminder_soon_sent_at']);
        });
    }
};
