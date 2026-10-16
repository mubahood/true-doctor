<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step sign-in and "sign out everywhere".
 *
 * The secret and the recovery codes are stored encrypted (the model casts
 * them); `two_factor_last_step` refuses a code that was already used, so a
 * code read over somebody's shoulder cannot be replayed inside its window.
 * `signed_out_everywhere_at` ends every web session that started before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Column by column, so a run that stopped half-way (MySQL does not
        // roll back DDL) can simply be run again.
        $columns = [
            'two_factor_secret' => fn (Blueprint $t) => $t->text('two_factor_secret')->nullable(),
            'two_factor_recovery_codes' => fn (Blueprint $t) => $t->text('two_factor_recovery_codes')->nullable(),
            'two_factor_confirmed_at' => fn (Blueprint $t) => $t->timestamp('two_factor_confirmed_at')->nullable(),
            'two_factor_last_step' => fn (Blueprint $t) => $t->unsignedBigInteger('two_factor_last_step')->nullable(),
            'signed_out_everywhere_at' => fn (Blueprint $t) => $t->timestamp('signed_out_everywhere_at')->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('users', $name)) {
                Schema::table('users', fn (Blueprint $table) => $add($table));
            }
        }

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            // dateTime: MySQL 5.7 refuses a NOT NULL timestamp with no default.
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step', 'signed_out_everywhere_at']);
        });
    }
};
