<?php

namespace App\Services\Install;

use App\Support\Edition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The facts about a standalone installation's own server that Settings →
 * Licence & updates shows and the API reports: the installed marker, whether
 * the scheduler is alive, the queue, disk, upload limit, newest backup, and
 * the newest release the last online check found.
 */
final class ServerHealth
{
    public function __construct(private readonly InstallationBackups $backups, private readonly UpdateChannel $channel) {}

    /**
     * @return array{installed:array<string,mixed>,heartbeat:?Carbon,queued:int,diskFree:?float,uploadLimit:string|false,backupAge:?int}
     */
    public function health(): array
    {
        $heartbeat = Cache::get('standalone:scheduler-heartbeat');
        $marker = (string) config('edition.installed_path');
        $installed = is_file($marker) ? (json_decode((string) file_get_contents($marker), true) ?: []) : [];

        return [
            'installed' => $installed,
            'heartbeat' => is_string($heartbeat) ? Carbon::parse($heartbeat) : null,
            'queued' => DB::table('jobs')->count(),
            'diskFree' => @disk_free_space(base_path()) ?: null,
            'uploadLimit' => ini_get('upload_max_filesize'),
            'backupAge' => $this->backups->newestAge(),
        ];
    }

    /**
     * The newer release the last online check found, or null.
     *
     * @return array<string,mixed>|null
     */
    public function availableUpdate(): ?array
    {
        $last = $this->channel->lastCheck();

        return is_array($last['latest'] ?? null) && version_compare((string) $last['latest']['version'], Edition::version(), '>')
            ? $last['latest'] : null;
    }
}
