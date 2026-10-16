<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A push token becomes a registered device of the app: which install it is
 * (device_uuid, the same id the app sends as X-Device-Id), who delivers to it
 * (provider), and what the server needs to time and word a message for it
 * (locale, tz). A token may be absent: the app registers its zone and
 * preferences even where there is no push service, and rotates tokens.
 *
 * Revoking never deletes (revoked_at): the row is the answer to "which phone
 * was that?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('device_uuid', 64)->nullable()->after('user_id');
            $table->string('provider', 16)->default('none')->after('platform');   // none | fcm | apns | webpush
            $table->string('app_version', 32)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('tz', 64)->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['user_id', 'device_uuid']);
        });

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('token', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'device_uuid']);
            $table->dropColumn(['device_uuid', 'provider', 'app_version', 'locale', 'tz', 'push_enabled', 'revoked_at', 'last_seen_at']);
        });
    }
};
