<?php

namespace App\Support\Insights;

/**
 * The common gate for rules: a permission, a practice module and an edition,
 * each optional. A subclass says which, and implements `evaluate()`.
 */
abstract class Rule implements InsightRule
{
    /** The permission needed to act on this, or null for anybody. */
    protected function permission(): ?string
    {
        return null;
    }

    /** The practice module that must be in use (Practice::MODULES), or null. */
    protected function module(): ?string
    {
        return null;
    }

    /** 'saas' or 'standalone', or null for both. */
    protected function edition(): ?string
    {
        return null;
    }

    public function applies(InsightContext $context): bool
    {
        if ($this->edition() !== null && $context->edition() !== $this->edition()) {
            return false;
        }

        if ($this->module() !== null && ! $context->uses($this->module())) {
            return false;
        }

        return $this->permission() === null || $context->can($this->permission());
    }

    protected static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    }
}
