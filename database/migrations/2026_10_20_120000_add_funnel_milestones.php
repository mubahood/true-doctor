<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The funnel from a visit to a working clinic (roadmap 02 §6): the first tap
 * on a WhatsApp button, on the visitor's trail; and on the hospital, the first
 * patient registered and the first visit paid (App\Support\Funnel). Each is
 * the first time only, and each column is added only if missing, so a server
 * that ran half of this before can run it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('traffic_sessions', 'whatsapp_at')) {
            Schema::table('traffic_sessions', function (Blueprint $table) {
                $table->dateTime('whatsapp_at')->nullable()->after('enquired_at');
            });
        }
        foreach (['first_patient_at', 'first_paid_visit_at'] as $column) {
            if (! Schema::hasColumn('hospitals', $column)) {
                Schema::table('hospitals', function (Blueprint $table) use ($column) {
                    $table->dateTime($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['first_patient_at', 'first_paid_visit_at'] as $column) {
            if (Schema::hasColumn('hospitals', $column)) {
                Schema::table('hospitals', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        if (Schema::hasColumn('traffic_sessions', 'whatsapp_at')) {
            Schema::table('traffic_sessions', fn (Blueprint $table) => $table->dropColumn('whatsapp_at'));
        }
    }
};
