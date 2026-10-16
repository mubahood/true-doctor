<?php

namespace App\Support\Insights\Rules;

use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;
use App\Support\OnboardingStatus;
use App\Support\StarterData;

/** The setup checklist still has steps open: the first one is where to go. */
final class SetupIncomplete extends Rule
{
    public function key(): string
    {
        return 'setup-incomplete';
    }

    protected function permission(): ?string
    {
        return 'manage-settings';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $status = app(OnboardingStatus::class);
        $next = $status->nextStep($c->hospital);
        if ($next === null) {
            return null;
        }

        $required = $status->requiredSteps($c->hospital)->filter(fn ($s) => ! $s->done)->count();
        $open = $status->steps($c->hospital)->filter(fn ($s) => ! $s->done)->count();

        return new Insight(
            $this->key(), $required > 0 ? 'warn' : 'info',
            $required > 0 ? 'Finish setting up your hospital' : 'Setup suggestions for your practice',
            "{$next->title}: {$next->summary}",
            StarterData::appRouteForStep($next->key, $c->practice), 'Continue setup', $open, $required > 0 ? 75 : 30,
        );
    }
}
