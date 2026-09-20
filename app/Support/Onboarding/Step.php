<?php

namespace App\Support\Onboarding;

/**
 * One step of hospital setup, with everything the wizard needs to render it:
 * whether it is done, whether it blocks access, what the hospital currently has,
 * and any issues worth flagging even when the step technically passes.
 *
 * Built by App\Support\OnboardingStatus, which is the single source of truth
 * shared by the wizard and the RequireOnboarding gate — the two can never drift.
 */
final readonly class Step
{
    /**
     * @param  string  $key  stable identifier, also the wizard's panel id
     * @param  string  $title  what the admin is being asked to do
     * @param  string  $summary  one line of what the step involves
     * @param  string  $why  why the system needs it (shown when the step is open)
     * @param  bool  $required  required steps block access until done
     * @param  bool  $done  the underlying data now satisfies the step
     * @param  string  $detail  the hospital's current state, e.g. "3 departments"
     * @param  list<string>  $issues  problems to fix even though the step may pass
     * @param  string|null  $route  the full module page, for advanced work
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $summary,
        public string $why,
        public string $icon,
        public bool $required,
        public bool $done,
        public string $detail,
        public array $issues = [],
        public ?string $route = null,
    ) {}

    /** Done, but with something the admin should still look at. */
    public function needsAttention(): bool
    {
        return $this->issues !== [];
    }

    /** The status word shown to screen readers and beside the icon. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->done && $this->needsAttention() => 'Done, needs attention',
            $this->done => 'Done',
            $this->required => 'Required',
            default => 'Recommended',
        };
    }

    public function statusTone(): string
    {
        return match (true) {
            $this->done && $this->needsAttention() => 'warn',
            $this->done => 'success',
            $this->required => 'danger',
            default => 'neutral',
        };
    }
}
