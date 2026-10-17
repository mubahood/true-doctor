<?php

namespace App\Support\Insights;

use App\Models\User;
use App\Support\HospitalSettings;
use App\Support\Practice\Practice;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Relevant suggestions" for one person: every rule that applies to them is
 * asked, what comes back is ranked (danger first) and cached for 60 seconds
 * per user. The limit is applied after the cache, so `?limit=` costs nothing.
 *
 * A rule that throws is reported and skipped: one broken check must not take
 * the whole list away from the person.
 */
final class InsightEngine
{
    public const TTL = 60;

    public const DEFAULT_LIMIT = 8;

    public const MAX_LIMIT = 20;

    /** @var list<class-string<InsightRule>> */
    public const RULES = [
        Rules\SetupIncomplete::class,
        Rules\NotifiableDiseases::class,
        Rules\StockExpired::class,
        Rules\StockRunningOut::class,
        Rules\StockExpiring::class,
        Rules\LongWaits::class,
        Rules\ExpectedNotArrived::class,
        Rules\NoShowsToday::class,
        Rules\OverdueInvoices::class,
        Rules\UnpaidInvoices::class,
        Rules\VisitsStuckOpen::class,
        Rules\LabWaiting::class,
        Rules\RadiologyWaiting::class,
        Rules\ClaimsAwaitingAction::class,
        Rules\InsurerFloatShort::class,
        Rules\BooksCatchUp::class,
        Rules\FinancialYearAttention::class,
        Rules\LongStayAdmissions::class,
        Rules\DoctorsWithoutAvailability::class,
        Rules\TrialEnding::class,
        Rules\LicenceUpdatesEnding::class,
        // Phase 2: money leaks and follow-up (roadmap 09 §1).
        Rules\VisitsClosedUnbilled::class,
        Rules\LargeDiscounts::class,
        Rules\ResultsAfterPatientLeft::class,
        Rules\DentalRecallsDue::class,
    ];

    /** @return list<array<string,mixed>> */
    public function for(User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        $hospital = app(HospitalSettings::class)->hospital();
        if ($hospital === null) {
            return []; // a platform account has no hospital to advise on
        }

        $ranked = Cache::remember(self::cacheKey($user, $hospital->id), self::TTL, fn () => $this->compute($user, $hospital));

        return array_slice($ranked, 0, max(1, $limit));
    }

    public static function cacheKey(User $user, int $hospitalId): string
    {
        return "insights:v1:{$hospitalId}:{$user->id}";
    }

    /** @return list<array<string,mixed>> */
    private function compute(User $user, \App\Models\Hospital $hospital): array
    {
        $context = new InsightContext($user, $hospital, Practice::of($hospital), now());
        $found = [];

        foreach (self::RULES as $class) {
            /** @var InsightRule $rule */
            $rule = app($class);

            try {
                if ($rule->applies($context) && ($insight = $rule->evaluate($context)) !== null) {
                    $found[] = $insight;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        usort($found, fn (Insight $a, Insight $b) => [$b->score(), $a->key] <=> [$a->score(), $b->key]);

        if ($found === []) {
            return [(new Insight(
                'all-clear', 'success', 'Nothing needs your attention',
                'No stock, billing, queue or setup issue stands out right now.',
                'admin.dashboard', 'Open the dashboard', null, 0,
            ))->toArray()];
        }

        return array_map(fn (Insight $i) => $i->toArray(), $found);
    }
}
