<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `devices.pull_cursor` holds an ENCRYPTED cursor, and `varchar(255)` is too
 * small for one.
 *
 * Laravel's encrypter emits base64 of a JSON envelope carrying an IV, the
 * ciphertext and a MAC. The overhead alone is about 210 characters before the
 * payload, and the payload grows with the number of pull streams — the real
 * one measured 288. So `POST /sync/ack` answered **500 on every call, for
 * every device**, with `Data too long for column 'pull_cursor'`.
 *
 * No test caught it because SQLite does not enforce VARCHAR lengths at all: it
 * stores whatever you give it and the assertion passes. This is the second
 * SQLite-versus-MySQL trap in this project, after the table-rebuild cascade in
 * `App\Support\SchemaKeys`, and it is worth stating the general rule — **a
 * column length is not verified by this test suite.**
 *
 * `text`, not a bigger `varchar`: the ciphertext has no useful upper bound, it
 * is never indexed, and it is never compared to anything. Picking another
 * number would only be picking the next one to outgrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('pull_cursor')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Clear what no longer fits before narrowing the column, or the change
        // fails on exactly the rows this migration was written for. A device's
        // server-side cursor is a convenience for the administrator's view —
        // the device's own copy is the authority for what it asks for next —
        // so dropping it costs a stale "last seen" and nothing more.
        DB::table('devices')
            ->whereRaw('LENGTH(pull_cursor) > 255')
            ->update(['pull_cursor' => null]);

        Schema::table('devices', function (Blueprint $table) {
            $table->string('pull_cursor', 255)->nullable()->change();
        });
    }
};
