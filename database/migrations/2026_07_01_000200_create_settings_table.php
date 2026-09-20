<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value app configuration editable by the Super Admin (site name, tagline,
 * default theme, enabled languages, contacts, verify rate-limit, QR base URL).
 * Domain config (categories, regulatory bodies, validity) lives in its own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group', 32)->default('general')->index();
            $table->string('type', 16)->default('string'); // string|bool|int|json
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
