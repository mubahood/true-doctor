<?php

namespace App\Console\Commands;

use App\Support\Edition;
use App\Support\Health\HealthCheck;
use Illuminate\Console\Command;

/** Everything that keeps a hospital running, checked in one go. Exit 1 when anything fails. */
class TdCheck extends Command
{
    protected $signature = 'td:check {--json : One JSON document instead of a table}';

    protected $description = 'Check the database, queue, scheduler, backups, disk, mail and production settings';

    public function handle(HealthCheck $health): int
    {
        $checks = $health->run();
        $overall = HealthCheck::overall($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => $overall, 'version' => Edition::version(), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'What it found'], array_map(fn ($c) => [$c['key'], strtoupper($c['status']), $c['message']], $checks));
            $this->line('Overall: '.strtoupper($overall).' (True-Doctor '.Edition::version().', '.Edition::name().')');
        }

        return $overall === HealthCheck::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
