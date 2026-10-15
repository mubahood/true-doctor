<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody whose role is not "doctor" can still see patients — the dentist
 * who also runs the clinic. Marked, they can be booked and rostered like a
 * doctor while keeping the role their other work needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sees_patients')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sees_patients');
        });
    }
};
