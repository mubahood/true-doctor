<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One person's choice per category of notification (see App\Notifications\Concerns\AppNotification). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 24);
            $table->boolean('enabled')->default(true);
            $table->json('channels')->nullable();          // e.g. ["database","push"]; null = all the category normally uses
            $table->string('quiet_start', 5)->nullable();  // HH:MM, in tz
            $table->string('quiet_end', 5)->nullable();
            $table->string('tz', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
