<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A push token was globally unique, so registering another user's token
 * re-pointed their device at the caller (cross-tenant hijack). Tokens are
 * now unique per user; the same physical token may legitimately belong to
 * several accounts (shared ward tablets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['token']);
            // 191 chars keeps the composite key inside utf8mb4 index limits.
            $table->string('token', 191)->change();
            $table->unique(['user_id', 'token']);
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'token']);
            $table->string('token', 512)->change();
            $table->unique('token');
        });
    }
};
