<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each scheduled job last ran, so a job is "due since its last
 * successful run" rather than "due at 03:40 exactly" (a cron that fires every
 * 19 minutes never lands on 03:40). Also the scheduler's own heartbeat, which
 * the health check reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_runs', function (Blueprint $table) {
            $table->string('name', 100)->primary();
            $table->dateTime('last_started_at')->nullable();
            $table->dateTime('last_succeeded_at')->nullable();
            $table->dateTime('last_failed_at')->nullable();
            $table->unsignedInteger('failures_in_a_row')->default(0);
            $table->unsignedInteger('runs')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_runs');
    }
};
