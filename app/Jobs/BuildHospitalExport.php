<?php

namespace App\Jobs;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Services\Compliance\HospitalExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds a hospital's full export (App\Services\Compliance\HospitalExport).
 *
 * On its own connection and queue (`data-exports` / `exports`) with a long
 * lease, drained by its own short-lived scheduled worker, so a big hospital's
 * export neither blocks the notification queue nor gets picked up twice.
 */
class BuildHospitalExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1700;

    public int $tries = 1;

    public function __construct(public readonly int $exportId) {}

    public function handle(HospitalExport $exports): void
    {
        // No tenant in a worker: find the row whichever hospital it is, then build as that hospital.
        $export = DataExport::query()->withoutGlobalScopes()->find($this->exportId);
        if ($export === null || $export->status !== DataExportStatus::Queued) {
            return;
        }

        $exports->build($export);
    }

    public function failed(\Throwable $exception): void
    {
        DataExport::query()->withoutGlobalScopes()->whereKey($this->exportId)
            ->whereIn('status', [DataExportStatus::Queued, DataExportStatus::Building])
            ->update(['status' => DataExportStatus::Failed, 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
    }
}
