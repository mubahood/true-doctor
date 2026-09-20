<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Activity log entries carry the tenant so PHI (e.g. diagnoses) can be scoped and purged per hospital (plan finding I17). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('hospital_id')->nullable()->after('id');
            $table->index(['hospital_id', 'created_at'], 'activity_log_hospital_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_hospital_created_idx');
            $table->dropColumn('hospital_id');
        });
    }
};
