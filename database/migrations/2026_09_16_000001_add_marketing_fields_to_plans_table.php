<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a super-admin market a plan, not just price it: a one-line tagline, a
 * bullet list of features (independent of the `limits` JSON, which is caps
 * enforcement, not copy), and a "most popular" flag to highlight one plan on
 * the subscription page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('description', 160)->nullable()->after('name');
            $table->json('features')->nullable()->after('limits');
            $table->boolean('is_featured')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['description', 'features', 'is_featured']);
        });
    }
};
