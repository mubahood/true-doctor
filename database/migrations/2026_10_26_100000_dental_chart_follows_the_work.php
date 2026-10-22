<?php

use App\Enums\DentalFinding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dental chart follows the work done (docs/dental.md, "The chart follows
 * the work"):
 *
 * - `services.marks_tooth_as`: what a finished procedure leaves on the tooth
 *   (a DentalFinding value: filled, missing, root canal, crown...). Null for a
 *   service that leaves no mark. Existing dental services get a guess from
 *   their name, which the price list can change.
 * - `orders.teeth`: the teeth a procedure order is done on (FDI numbers).
 * - `dental_chart_entries.order_id`: the order a chart entry came from, so a
 *   completed order charts its teeth once and never twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('marks_tooth_as', 20)->nullable()->after('category');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->json('teeth')->nullable()->after('notes');
        });

        Schema::table('dental_chart_entries', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('visit_id')->constrained('orders')->nullOnDelete();
        });

        $this->guessExistingMarks();
    }

    /** Dental services (a dental clinic's, or filed under a dental specialty) get a guess from their name. */
    private function guessExistingMarks(): void
    {
        $clinics = DB::table('hospitals')->get(['id', 'settings'])
            ->filter(function ($h) {
                $settings = is_string($h->settings) ? json_decode($h->settings, true) : null;

                return is_array($settings) && (($settings['practice']['sector'] ?? null) === 'dental_clinic');
            })
            ->pluck('id')->all();

        DB::table('services')
            ->whereNull('marks_tooth_as')
            ->where(fn ($q) => $q->whereIn('hospital_id', $clinics)->orWhere('category', 'like', '%dent%'))
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($service) {
                $mark = DentalFinding::guessFromService((string) $service->name);
                if ($mark !== null) {
                    DB::table('services')->where('id', $service->id)->update(['marks_tooth_as' => $mark->value]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('dental_chart_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('teeth');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('marks_tooth_as');
        });
    }
};
