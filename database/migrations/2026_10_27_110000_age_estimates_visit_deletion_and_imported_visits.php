<?php

use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dental clinic's review, October 2026 (docs/decisions.md):
 *
 * - `patients.dob_estimated`: the date of birth was worked out from an age
 *   typed at the desk, not given. Every existing date stays exact.
 * - `visits.deleted_by` / `visits.delete_reason`: who deleted a visit and why
 *   (VisitService::delete). The visit itself is soft-deleted, as before.
 * - `invoices.paid_before`: what a visit brought in from the old records
 *   had already been paid in the old books (Settings, Import data, Visits).
 *   Only what was still owed goes into these books; the rest is history.
 * - The `visits.delete` permission, given to the hospital admin.
 *
 * No foreign keys added: on SQLite that rebuilds the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('dob_estimated')->default(false)->after('dob');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('delete_reason', 255)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('paid_before', 12, 2)->default(0);
        });

        RbacSeeder::applyWhenSeeded();
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('paid_before'));
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn(['deleted_by', 'delete_reason']));
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn('dob_estimated'));
    }
};
