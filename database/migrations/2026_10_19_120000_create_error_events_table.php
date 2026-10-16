<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Errors the system hit, grouped by where they happened, with personal data
 * scrubbed out (ErrorTracker). One row per kind of error, counting how often
 * and keeping the references people quote to support. Platform data, not a
 * tenant's: the hospital is recorded, never used to scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('source', 12);
            $table->string('class', 191);
            $table->text('message');
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('path', 255)->nullable();
            $table->string('last_ref', 12)->nullable();
            $table->text('refs')->nullable();
            $table->unsignedBigInteger('last_user_id')->nullable();
            $table->unsignedBigInteger('last_hospital_id')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('release', 32)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
