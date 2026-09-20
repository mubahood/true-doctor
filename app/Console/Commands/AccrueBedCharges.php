<?php

namespace App\Console\Commands;

use App\Enums\AdmissionStatus;
use App\Models\Admission;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Put last night on every open stay's bill.
 *
 * A stay is an order on a visit, and every other kind of order accumulates
 * the items it actually consumed. This is the one that does it for an
 * inpatient bed: once a night is complete, it goes on the order as its own
 * line, priced at what the bed cost that night.
 *
 * Idempotent by construction — the service bills the difference between the
 * nights completed and the nights already on the bill, so running this twice
 * in a day adds nothing, and the first run after three days of downtime adds
 * the three nights that were missed. There is no window in which a night is
 * billed twice or skipped.
 *
 * Nothing here is a substitute for discharge: a stay that closes still bills
 * whatever nights are outstanding on the way out. This only means the bill is
 * already right before then.
 */
class AccrueBedCharges extends Command
{
    protected $signature = 'admissions:accrue-bed-charges
                            {--date= : Bill as though it were this date (Y-m-d), for catching up}
                            {--dry-run : Say what would be billed and change nothing}';

    protected $description = 'Add a night to the bill of every open inpatient stay';

    public function handle(AdmissionService $admissions): int
    {
        $asOf = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();
        $dry = (bool) $this->option('dry-run');

        // Across every tenant, because a scheduled command belongs to no
        // hospital. Each admission is then billed with ITS hospital resolved,
        // so the order items it creates are written into the right tenant —
        // `BelongsToHospital` fills `hospital_id` from `CurrentHospital`, and
        // leaving it unset would put a bed charge on nobody's books.
        $open = Admission::withoutGlobalScopes()
            ->where('status', AdmissionStatus::Admitted->value)
            ->orderBy('hospital_id')
            ->orderBy('id')
            ->get();

        $current = app(CurrentHospital::class);
        $was = $current->id();

        $nights = 0;
        $stays = 0;
        $failed = 0;

        foreach ($open as $admission) {
            try {
                $current->set($admission->hospital_id);

                if ($dry) {
                    $this->line("  would bill: admission #{$admission->id} (hospital {$admission->hospital_id})");

                    continue;
                }

                $added = $admissions->accrueNightlyCharges($admission, $asOf);

                if ($added > 0) {
                    $nights += $added;
                    $stays++;
                }
            } catch (Throwable $e) {
                // One stay that cannot be billed must not stop the rest. A
                // ward of patients going unbilled because of one bad row is a
                // far worse outcome than one row needing a person.
                $failed++;
                $this->error("  admission #{$admission->id}: {$e->getMessage()}");
                report($e);
            }
        }

        $current->set($was);

        $this->info(sprintf(
            'admissions:accrue-bed-charges — %d night(s) across %d stay(s) as of %s.%s',
            $nights,
            $stays,
            $asOf->toDateString(),
            $failed > 0 ? " {$failed} could not be billed." : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
