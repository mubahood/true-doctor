<?php

namespace App\Livewire\Onboarding;

use App\Support\HospitalSettings;
use App\Support\Onboarding\Step;
use App\Support\OnboardingStatus;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The setup wizard a new hospital must complete before the rest of the system
 * opens up (App\Http\Middleware\RequireOnboarding enforces it — there is no
 * skip). Steps are completed in place: each one expands into a small form that
 * writes through the same services and validation rules the full modules use,
 * so nothing here is a parallel implementation.
 *
 * State is derived, never stored: OnboardingStatus reads the hospital's actual
 * data, which means a step completed anywhere else in the system — or undone —
 * is reflected here immediately.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    /** The expanded step; deep-linkable so "continue setup" links can target one. */
    #[Url(as: 'step', history: true, except: '')]
    public string $open = '';

    public function mount(): void
    {
        $this->authorizeView();

        if ($this->open === '' || ! $this->stepKeys()->contains($this->open)) {
            $this->open = $this->firstOutstandingKey();
        }
    }

    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->can('access-admin'), 403);
    }

    private function status(): OnboardingStatus
    {
        return app(OnboardingStatus::class);
    }

    private function hospital(): ?\App\Models\Hospital
    {
        return app(HospitalSettings::class)->hospital();
    }

    /** @return \Illuminate\Support\Collection<int, Step> */
    #[Computed]
    public function steps()
    {
        return $this->status()->steps($this->hospital());
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function stepKeys()
    {
        return $this->steps()->map(fn (Step $s) => $s->key);
    }

    /** The step the wizard should open, or '' when everything is done. */
    private function firstOutstandingKey(): string
    {
        $next = $this->status()->nextStep($this->hospital());

        return $next instanceof Step ? $next->key : '';
    }

    /** @return array{done:int,total:int,percent:int} */
    #[Computed]
    public function progress(): array
    {
        return $this->status()->progress($this->hospital());
    }

    #[Computed]
    public function isComplete(): bool
    {
        return $this->status()->isComplete($this->hospital());
    }

    /** Required steps still outstanding — the "what is blocking me" list. @return \Illuminate\Support\Collection<int, Step> */
    #[Computed]
    public function blocking()
    {
        return $this->steps()->filter(fn (Step $s) => $s->required && ! $s->done)->values();
    }

    /** @return list<string> */
    #[Computed]
    public function issues(): array
    {
        return $this->status()->issues($this->hospital());
    }

    #[Computed]
    public function subscription()
    {
        return $this->hospital()?->activeSubscription();
    }

    /** Only a configuring admin sees the forms; other staff see the checklist read-only. */
    #[Computed]
    public function canConfigure(): bool
    {
        return (bool) Auth::user()?->can('manage-settings');
    }

    public function toggle(string $key): void
    {
        $this->open = $this->open === $key ? '' : $key;
    }

    /**
     * A step reported success: re-read the checklist so its status, the
     * progress bar and the "still needed" list catch up immediately. Does NOT
     * change which step is open — the admin decides when to move on, via the
     * "Next" button `next()` reveals once the open step is done.
     */
    #[On('onboarding-updated')]
    public function refresh(): void
    {
        unset($this->steps, $this->progress, $this->isComplete, $this->blocking, $this->issues);
    }

    /** Move to the step right after the one currently open, if there is one. */
    public function next(): void
    {
        $keys = $this->stepKeys()->values();
        $at = $keys->search($this->open);

        if ($at !== false && $keys->has($at + 1)) {
            $this->open = $keys->get($at + 1);
        }
    }

    /** Leave the wizard once the required steps are done. */
    public function finish()
    {
        $this->authorizeView();

        if (! $this->status()->isComplete($this->hospital())) {
            $this->dispatch('toast', message: 'A required step is still outstanding.', type: 'error');

            return null;
        }

        session()->flash('success', 'Setup complete — your hospital is ready to use.');

        return $this->redirectRoute('admin.dashboard', navigate: true);
    }

    public function render()
    {
        $this->authorizeView();

        return view('livewire.onboarding.index')->title('Set up your hospital');
    }
}
