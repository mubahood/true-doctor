<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's daily summary (roadmap 09 §2): one row per owner per day. The
 * unique key is what makes "once a day" true however often the scheduler
 * runs, and the row is the record of what was sent, how, and what became of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('owner_summaries')) {
            return;
        }

        Schema::create('owner_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');
            $table->string('channel', 16);
            $table->string('recipient', 191)->nullable();
            $table->text('body');
            $table->string('status', 16)->index();
            $table->string('error', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'summary_date'], 'owner_summaries_once_a_day');
            $table->index(['hospital_id', 'summary_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_summaries');
    }
};
