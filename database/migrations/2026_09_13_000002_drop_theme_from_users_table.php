<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The back-office is light-only (commit d2b36cb); the per-user theme preference was never applied. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'theme')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('theme'));
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('theme', 10)->default('light'));
    }
};
