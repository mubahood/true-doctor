<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The answers to API writes sent with an Idempotency-Key, kept 24 hours so a retry replays instead of repeating. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('body');
            $table->timestamp('created_at')->nullable()->index();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
