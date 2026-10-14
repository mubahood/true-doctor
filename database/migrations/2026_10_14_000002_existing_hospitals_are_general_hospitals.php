<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every hospital that exists today set itself up from one catalogue — the
 * general hospital's, every specialty at once — because there was no other.
 *
 * Choosing a kind of practice is now the first step of setup, and it is a
 * required one. A hospital already in business must not be pulled back into
 * the wizard to answer it, so it is recorded here as what it effectively
 * chose: a general hospital, with every module in use. It can change that from
 * Settings → Your practice whenever it likes; nothing it already has changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hospitals')->orderBy('id')->each(function (object $hospital) {
            $settings = json_decode((string) ($hospital->settings ?? ''), true);
            $settings = is_array($settings) ? $settings : [];

            if (is_array($settings['practice'] ?? null) && filled($settings['practice']['sector'] ?? null)) {
                return;
            }

            $settings['practice'] = [
                'sector' => 'general_hospital',
                'packs' => [],          // a general hospital's own packs are all of them
                'modules' => null,      // null: every module, as before
                'chosen_at' => null,    // recorded, not chosen
            ];

            DB::table('hospitals')->where('id', $hospital->id)->update(['settings' => json_encode($settings)]);
        });
    }

    public function down(): void
    {
        // The answer recorded here is indistinguishable from one a hospital
        // gave, so it is left alone rather than guessed at.
    }
};
