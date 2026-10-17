<?php

namespace App\Console\Commands;

use App\Services\OwnerSummaryService;
use Illuminate\Console\Command;

/**
 * The owner's daily summary (roadmap 09 §2), for every owner whose time has
 * come. Idempotent: one summary per owner per day (owner_summaries' unique
 * key), so it runs every half hour and a late or doubled cron changes nothing.
 */
class SendOwnerSummaries extends Command
{
    protected $signature = 'owners:daily-summary';

    protected $description = "Send each hospital owner who asked for it yesterday's summary (once a day, outside quiet hours)";

    public function handle(OwnerSummaryService $summaries): int
    {
        $result = $summaries->sendDue();
        $this->info("owners:daily-summary: {$result['sent']} sent, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
