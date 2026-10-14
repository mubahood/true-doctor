<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            // NULL is unrestricted for SaaS; 1 is the only slot in standalone.
            // The unique index closes the race between two concurrent creates.
            $table->unsignedTinyInteger('standalone_slot')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropUnique(['standalone_slot']);
            $table->dropColumn('standalone_slot');
        });
    }
};
