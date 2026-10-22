<?php

use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * `visits.report`: printing the visit report without reading the clinical
 * record on screen (docs/rbac.md). A dental clinic's desk starts with it
 * (App\Support\Access\PracticeDefaults); the hospital admin has it. An
 * existing database's roles are brought up to the matrix; a fresh one is left
 * to the seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        RbacSeeder::applyWhenSeeded();
    }

    public function down(): void
    {
        // The permission is harmless where nothing checks it.
    }
};
