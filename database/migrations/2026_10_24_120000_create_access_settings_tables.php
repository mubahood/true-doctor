<?php

use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff and access (docs/rbac.md): what a clinic changed about a role, and the
 * extra abilities it gave one person. Both sit ON TOP of the default matrix in
 * RbacSeeder, which an update re-applies; keeping the clinic's choices in rows
 * of their own is what stops an update from undoing them.
 *
 * It also brings an existing database's roles up to the tightened defaults
 * (two new permissions, and doctors no longer seeing all the money). A fresh
 * database has no roles yet and is left to the seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_abilities')) {
            Schema::create('role_abilities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->string('role', 32);
                $table->string('ability', 32);
                $table->boolean('allowed');
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['hospital_id', 'role', 'ability'], 'role_abilities_one_choice');
            });
        }

        if (! Schema::hasTable('user_abilities')) {
            Schema::create('user_abilities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('ability', 32);
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'ability'], 'user_abilities_once');
                $table->index(['hospital_id', 'user_id']);
            });
        }

        RbacSeeder::applyWhenSeeded();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_abilities');
        Schema::dropIfExists('role_abilities');
    }
};
