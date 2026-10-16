<?php

namespace App\Support\Insights\Rules;

use App\Support\Edition;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;
use App\Support\Licensing\InstalledLicence;

/** Standalone: the licence's window for updates is closing or has closed. */
final class LicenceUpdatesEnding extends Rule
{
    public const DAYS = 30;

    public function key(): string
    {
        return 'licence-updates-ending';
    }

    protected function permission(): ?string
    {
        return 'manage-settings';
    }

    protected function edition(): ?string
    {
        return Edition::STANDALONE;
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $until = app(InstalledLicence::class)->updatesUntil();
        if ($until === null) {
            return null;
        }

        $days = (int) $c->now->copy()->startOfDay()->diffInDays($until->copy()->startOfDay(), false);
        if ($days > self::DAYS) {
            return null;
        }

        return new Insight(
            $this->key(), $days < 0 ? 'danger' : 'warn',
            $days < 0 ? 'Your updates window has ended' : 'Your updates window ends in '.self::plural($days, 'day'),
            $days < 0
                ? 'This installation keeps working, but new versions and fixes are no longer covered.'
                : 'Renew to keep receiving new versions and fixes.',
            'admin.system.index', 'Open licence & updates', max(0, $days), 50,
        );
    }
}
