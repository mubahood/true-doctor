<?php

namespace App\Support\Insights;

/**
 * One thing worth telling somebody about. To add a suggestion: write a class
 * that extends Rule (or implements this), and list it in InsightEngine::RULES.
 *
 * A rule must be cheap: aggregate queries only, nothing per row. It is asked
 * only when `applies()` says this person may act on it and this practice does
 * the work, so `evaluate()` never has to repeat a permission check.
 */
interface InsightRule
{
    /** Stable identifier; also the Insight's key. */
    public function key(): string;

    /** Whether this person (permissions), practice (modules) and edition could act on it. */
    public function applies(InsightContext $context): bool;

    /** The suggestion, or null when there is nothing to say right now. */
    public function evaluate(InsightContext $context): ?Insight;
}
