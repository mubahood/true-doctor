<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shared invoice or receipt link carries this nonce in its signed URL.
 * Changing it (or clearing it) makes every link issued before stop working,
 * which is how a link is revoked (docs/api/journeys.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('share_nonce', 24)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('share_nonce');
        });
    }
};
