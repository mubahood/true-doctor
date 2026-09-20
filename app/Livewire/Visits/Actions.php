<?php

namespace App\Livewire\Visits;

use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidVisitTransitionException;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\VisitService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * The visit workspace, in a dialog — the whole visit on one page.
 *
 * Mount this once on a page and dispatch `visit-action` at it:
 *
 *     $dispatch('visit-action', { visitId: 12, section: 'vitals' })
 *
 * It opens ONE modal containing EVERY section of the visit at once, in the
 * order the work happens:
 *
 *     Summary  →  Orders  →  Bill  →  Payments  →  History
 *
 * Summary carries the vitals and the clinical notes, because that is what a
 * summary of a visit IS. Orders carries every piece of work of every
 * kind — the kinds are chips inside it, not entries in the rail
 * (docs/orders.md).
 *
 * `section` only says where to scroll on arrival — the rail jumps in the
 * browser, with no round trip. Nothing here swaps one view for another, so
 * nothing has to be re-fetched to go back.
 *
 * The panels are mounted with :lazy="false" deliberately. They carry #[Lazy]
 * for the detail page, where a panel below the fold should not cost a query;
 * here everything is present from the first paint, which is the point.
 *
 * A panel takes only a #[Locked] visit id, re-resolves the visit through the
 * tenant scope, authorises itself and writes through its own service — so it
 * is already a complete, safe unit of work, and this dialog gets the real
 * thing rather than a second implementation that would drift from it.
 *
 * @property-read Visit|null $visit
 * @property-read array $totals
 * @property-read array<string, array{label:string,icon:string,count:int|null,component:string|null}> $sections
 * @property-read bool $canOverride
 * @property-read array{stage:VisitStage,next:?VisitStage,ready:bool,blocker:?string,label:?string,automatic:bool} $gate
 */
class Actions extends Component
{
    use AuthorizesRequests;

    /**
     * The page, in order. section key => [label, icon, the ability that must
     * hold to render it at all, the panel component or null for local markup].
     *
     * Gating mirrors the detail page exactly: a panel that renders read-only
     * for the unprivileged is always shown (it decides), and one that has no
     * read-only mode is hidden unless the ability holds.
     */
    public const SECTIONS = [
        // Who the patient is and what was found — vitals and clinical notes
        // live here, not in tabs of their own: they ARE the summary of the
        // visit, and a reader who wants one wants the other.
        'summary' => ['Summary', 'fa-circle-info', null, null],
        // Everything being done for the patient, of every kind, in one list.
        'orders' => ['Orders', 'fa-list-check', null, 'visits.panels.orders'],
        'bill' => ['Bill', 'fa-file-invoice-dollar', 'billing.view', 'visits.panels.charges'],
        'payments' => ['Payments', 'fa-hand-holding-dollar', 'billing.view', 'visits.panels.payments'],
        'history' => ['History', 'fa-clock-rotate-left', null, null],
    ];

    #[Locked]
    public ?int $visitId = null;

    public bool $show = false;

    /** Where to scroll on open. A scroll target, never a view. */
    #[Locked]
    public string $section = 'summary';

    /**
     * @param  bool  $add  open the add-order form along with the dialog
     */
    #[On('visit-action')]
    public function openAction(int $visitId, string $section = 'summary', bool $add = false): void
    {
        // Tenant-scoped: another hospital's id is a 404, never a silent no-op.
        $visit = Visit::findOrFail($visitId);
        $this->authorize('view', $visit);

        $this->visitId = $visitId;
        $this->section = isset(self::SECTIONS[$section]) ? $section : 'summary';
        $this->showStage = false;
        $this->showCancel = false;
        $this->resetErrorBag();
        $this->show = true;

        // Dispatched from here rather than by the caller: the panel does not
        // exist in the DOM until this response renders it, so an event sent
        // alongside `visit-action` would arrive before its listener did.
        if ($add) {
            $this->dispatch('orders-add')->to('visits.panels.orders');
        }
    }

    /**
     * Everything about this visit, in one pass.
     *
     * The dialog shows every section at once, so it loads every relation at
     * once — one query per relation on open, rather than a fetch each time the
     * reader looks at something new.
     */
    #[Computed]
    public function visit(): ?Visit
    {
        if ($this->visitId === null) {
            return null;
        }

        return Visit::query()
            ->with([
                'patient', 'doctor', 'department', 'appointment',
                'orders.items', 'invoices',
                'admissions.bed.ward', 'history.changedBy',
            ])
            ->findOrFail($this->visitId);
    }

    /** What the visit owes, and what is left on it. */
    #[Computed]
    public function totals(): array
    {
        $visit = $this->visit;

        return $visit === null ? [] : app(BillingService::class)->totalsFor($visit);
    }

    /**
     * The rail: the sections this user actually gets, with what is on each.
     *
     * One entry per section and nothing nested: the kinds of order are chips
     * inside the Orders section, where they belong, because they narrow one
     * list rather than naming places to go.
     *
     * @return array<string, array{label:string,icon:string,count:int|null,component:string|null}>
     */
    #[Computed]
    public function sections(): array
    {
        $visit = $this->visit;
        if ($visit === null) {
            return [];
        }

        $counts = [
            'orders' => $visit->orders->count(),
            'payments' => $visit->invoices->first()?->payments()->count() ?? 0,
        ];

        $out = [];
        foreach (self::SECTIONS as $key => [$label, $icon, $ability, $component]) {
            if ($ability !== null && ! Auth::user()?->can($ability)) {
                continue;
            }

            $out[$key] = [
                'label' => $label,
                'icon' => $icon,
                'count' => $counts[$key] ?? null,
                'component' => $component,
            ];
        }

        return $out;
    }

    /** Any panel wrote something — re-read the visit so every part agrees. */
    #[On('visit-updated')]
    public function refresh(): void
    {
        unset($this->visit, $this->totals, $this->sections, $this->gate, $this->canOverride);
    }

    // ── Moving the visit along ───────────────────────────────────────────

    /**
     * The dialog for putting a visit somewhere by hand. It opens with or
     * without the visit dialog behind it, so a row menu can reach it without
     * first loading the whole visit.
     */
    public bool $showStage = false;

    public ?string $stageStatus = null;

    public ?string $stageStage = null;

    public ?string $stageOutcome = null;

    public ?string $stageNote = null;

    /** The cancel dialog is separate: it is one decision, and it needs a reason. */
    public bool $showCancel = false;

    public ?string $cancelNote = null;

    /**
     * What the visit's own facts say it may do next — see docs/visits.md.
     *
     * @return array{stage:VisitStage,next:?VisitStage,ready:bool,blocker:?string,label:?string,automatic:bool}
     */
    #[Computed]
    public function gate(): array
    {
        $visit = $this->visit;

        return $visit === null
            ? ['stage' => VisitStage::Ongoing, 'next' => null, 'ready' => false,
                'blocker' => null, 'label' => null, 'automatic' => false]
            : app(VisitService::class)->readiness($visit);
    }

    /** Whether this reader may put a visit anywhere, gates or no gates. */
    #[Computed]
    public function canOverride(): bool
    {
        $visit = $this->visit;

        return $visit !== null && Auth::user()?->can('overrideStage', $visit) === true;
    }

    /** Press the gate open. There is only ever one next stage, so no target. */
    public function advance(VisitService $service): void
    {
        $visit = $this->visit;
        if ($visit === null) {
            return;
        }
        $this->authorize('manage', $visit);

        try {
            $service->advance($visit, Auth::id());
        } catch (InvalidVisitTransitionException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Now at '.$this->visit?->stage->label().'.', type: 'success');
        $this->dispatch('visit-updated');
    }

    // ── Calling it off ───────────────────────────────────────────────────

    #[On('visit-cancel')]
    public function openCancel(?int $visitId = null): void
    {
        $this->loadIfGiven($visitId);

        $visit = $this->visit;
        if ($visit === null) {
            return;
        }
        $this->authorize('manage', $visit);

        $this->cancelNote = null;
        $this->resetErrorBag();
        $this->showCancel = true;
    }

    public function closeCancel(): void
    {
        $this->showCancel = false;
        $this->cancelNote = null;
    }

    public function cancelVisit(VisitService $service): void
    {
        $visit = $this->visit;
        if ($visit === null) {
            return;
        }
        $this->authorize('manage', $visit);

        $this->validate(
            ['cancelNote' => ['required', 'string', 'min:3', 'max:255']],
            ['cancelNote.required' => 'Say why the visit is being called off.',
                'cancelNote.min' => 'Say why the visit is being called off.'],
        );

        try {
            $service->cancel($visit, Auth::id(), trim((string) $this->cancelNote));
        } catch (InvalidVisitTransitionException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->closeCancel();
        $this->refresh();
        $this->dispatch('toast', message: 'Visit cancelled.', type: 'success');
        $this->dispatch('visit-updated');
    }

    // ── Putting it anywhere ──────────────────────────────────────────────

    #[On('visit-stage')]
    public function openStage(?int $visitId = null): void
    {
        $this->loadIfGiven($visitId);

        $visit = $this->visit;
        if ($visit === null) {
            return;
        }
        $this->authorize('manage', $visit);
        $this->authorize('overrideStage', $visit);

        $this->stageStatus = $visit->status->value;
        $this->stageStage = $visit->stage->value;
        $this->stageOutcome = $visit->outcome?->value;
        $this->stageNote = null;
        $this->resetErrorBag();
        $this->showStage = true;
    }

    public function closeStage(): void
    {
        $this->showStage = false;
        $this->stageNote = null;
    }

    /**
     * Keep the two pickers honest as they are used.
     *
     * An outcome only means anything on a finished visit, and a finished visit
     * always has one — so choosing Completed offers the outcome, and choosing
     * anything else takes it away.
     */
    public function updatedStageStatus(): void
    {
        if ($this->stageStatus === VisitStatus::Completed->value) {
            $this->stageOutcome ??= VisitOutcome::Closed->value;
            $this->stageStage = $this->stageOutcome === VisitOutcome::Closed->value
                ? VisitStage::Completed->value
                : $this->stageStage;
        } else {
            $this->stageOutcome = null;
            if ($this->stageStage === VisitStage::Completed->value) {
                $this->stageStage = VisitStage::Payment->value;
            }
        }
    }

    public function changeState(VisitService $service): void
    {
        $visit = $this->visit;
        if ($visit === null) {
            return;
        }
        $this->authorize('manage', $visit);
        $this->authorize('overrideStage', $visit);

        $this->validate([
            'stageStatus' => ['required', new Enum(VisitStatus::class)],
            'stageStage' => ['required', new Enum(VisitStage::class)],
            'stageOutcome' => ['nullable', new Enum(VisitOutcome::class)],
            'stageNote' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'stageNote.required' => 'Say why it is being changed out of order.',
            'stageNote.min' => 'Say why it is being changed out of order.',
        ]);

        try {
            $service->overrideState(
                $visit,
                VisitStatus::from((string) $this->stageStatus),
                VisitStage::from((string) $this->stageStage),
                $this->stageOutcome !== null ? VisitOutcome::from($this->stageOutcome) : null,
                Auth::id(),
                (string) $this->stageNote,
            );
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->closeStage();
        $this->refresh();
        $this->dispatch('toast',
            message: 'Set to '.$this->visit?->stateLabel().' — recorded as a change out of order.',
            type: 'success');
        $this->dispatch('visit-updated');
    }

    /** Row menus pass an id; the visit dialog is already on one. */
    private function loadIfGiven(?int $visitId): void
    {
        if ($visitId === null) {
            return;
        }

        $visit = Visit::findOrFail($visitId);
        $this->authorize('view', $visit);
        $this->visitId = $visitId;
        $this->refresh();
    }

    public function render()
    {
        return view('livewire.visits.actions');
    }
}
