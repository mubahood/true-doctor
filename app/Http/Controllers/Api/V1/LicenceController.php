<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Install\InstallationBackups;
use App\Services\Install\ServerHealth;
use App\Services\Install\SystemState;
use App\Support\ApiResponse;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Licence & updates (standalone only, manage-settings): which release is
 * running, what the licence covers, whether the scheduler and backups are
 * alive and whether a newer release is waiting. Read-only: updating, backing
 * up and uploading a release stay on the web, where the scheduler's requests
 * and the large upload live.
 */
class LicenceController extends Controller
{
    public function __invoke(Request $request, InstalledLicence $licence, ServerHealth $health, SystemState $state, InstallationBackups $backups): JsonResponse
    {
        abort_unless($request->user()->can('manage-settings'), 403);

        $facts = $health->health();
        $until = $licence->updatesUntil();
        $available = $health->availableUpdate();
        $heartbeat = $facts['heartbeat'];
        $current = $state->current();

        return ApiResponse::success([
            'edition' => Edition::name(),
            'version' => Edition::version(),
            'installed' => $facts['installed'],
            'licence' => [
                'valid' => $licence->isValid(),
                'id' => $licence->id(),
                'holder' => $licence->customer(),
                'updates_until' => $until?->toDateString(),
                'updates_covered' => $until === null ? null : $until->endOfDay()->isFuture(),
                'staff_limit' => $licence->limit('max_staff'),
                'address_matches' => $licence->isValid() && $licence->matchesHost($request->getHost()),
                'host' => $request->getHost(),
            ],
            'update' => [
                'available' => $available !== null,
                'latest' => $available === null ? null : [
                    'version' => $available['version'] ?? null,
                    'notes' => $available['notes'] ?? null,
                    'published_at' => $available['published_at'] ?? null,
                    'size' => $available['size'] ?? null,
                ],
                'status' => $current['status'] ?? 'idle',
                'message' => $current['message'] ?? null,
                'busy' => in_array($current['status'] ?? null, ['queued', 'running'], true),
                // Updating is done on the owner's web screen.
                'web_url' => route('admin.system.index'),
            ],
            'health' => [
                'scheduler_heartbeat_at' => $heartbeat?->toIso8601String(),
                'scheduler_alive' => $heartbeat !== null && $heartbeat->gt(now()->subMinutes(10)),
                'queued_jobs' => $facts['queued'],
                'disk_free_bytes' => $facts['diskFree'],
                'upload_limit' => $facts['uploadLimit'] === false ? null : $facts['uploadLimit'],
                'newest_backup_age_seconds' => $facts['backupAge'],
            ],
            'backups' => array_map(fn (array $b) => [
                'name' => $b['name'], 'size' => $b['size'], 'created_at' => date('c', $b['created_at']),
            ], $backups->list()),
        ]);
    }
}
