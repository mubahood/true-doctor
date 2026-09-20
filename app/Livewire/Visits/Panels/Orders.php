<?php

namespace App\Livewire\Visits\Panels;

use App\Enums\OrderType;
use App\Enums\VisitStage;
use App\Models\Order;
use App\Models\Visit;
use App\Services\OrderService;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Everything being done for this patient, in one list.
 *
 * This is the section the whole model points at (docs/orders.md): a lab test, a
 * scan, drugs, a consultation, a procedure, an admission — every one of them is
 * an order, so every one of them is a row here. The chips across the top are a
 * lens on the same list, never a different list — which is the point of having
 * one shape for every piece of work.
 *
 * @property-read Collection<int,Order> $orders
 * @property-read array<string,int> $counts
 * @property-read list<array{id:int,name:string,price:string}> $catalogue
 * @property-read int $catalogueTotal
 * @property-read list<string> $titleSuggestions
 * @property-read bool $writable
 */
#[Lazy]
class Orders extends Component
{
    use AuthorizesRequests, InteractsWithVisit, ShowsTheNextStep;

    /** This section's work is what opens the gate out of Ongoing. */
    protected function ownsStage(): VisitStage
    {
        return VisitStage::Ongoing;
    }

    /** '' = everything; otherwise an OrderType value. */
    public string $filter = '';

    public bool $showAdd = false;

    public ?string $type = null;

    public ?string $title = null;

    public ?int $assigned_to = null;

    public ?int $department_id = null;

    public ?string $notes = null;

    /** Type-specific pickers: which tests, which studies, which drugs. */
    public array $test_ids = [];

    public array $study_ids = [];

    /** Where the patient is going, when the work is an admission. */
    public ?int $ward_id = null;

    public ?int $bed_id = null;

    /**
     * Narrows a long catalogue. Filtered in the QUERY, never in the view: a
     * hospital with four hundred lab tests must not ship four hundred rows to
     * the browser so JavaScript can hide most of them.
     */
    public string $pick = '';

    /** Catalogue rows are capped — a picker is for finding, not for browsing. */
    private const PICK_LIMIT = 40;

    /** Enough phrasings to recognise one, not so many that reading them is work. */
    private const TITLE_LIMIT = 6;

    /** The pickers a <livewire:ui.select-search> child may feed here. */
    private const PICKERS = ['assigned_to', 'department_id', 'ward_id', 'bed_id'];

    /**
     * Bumped every time the form opens, and part of each picker's key.
     *
     * Without it the pickers keep their own `selected` across opens: the panel
     * clears `assigned_to`, the child does not hear about it, and the next
     * dialog shows the last person picked as though it had been chosen again.
     */
    public int $formNonce = 0;

    /**
     * What was just placed, if anything.
     *
     * The dialog does not vanish on success. Placing an order is usually not
     * the only thing being done for a patient — the doctor who orders bloods
     * often orders a scan in the same breath — so it says what it did and asks
     * whether to go again, rather than dropping the user back on the list to
     * find the button a second time.
     */
    public ?string $placed = null;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
    }

    /** The dialog header's quick action opens the same form the panel does. */
    #[On('orders-add')]
    public function openAddFromHeader(): void
    {
        $this->openAdd();
    }

    /** One order, in full, in a dialog over the list. */
    /**
     * Open one order to be worked on.
     *
     * The list only decides *which*; everything about managing it belongs to
     * Panels\OrderDetail, which is where the items, the report and the status
     * live. Scoped here too, so a forged id never reaches the dialog.
     */
    public function view(int $orderId): void
    {
        $this->authorize('view', $this->visit);

        // Tenant- and visit-scoped: another visit's order id is a 404.
        Order::where('visit_id', $this->visitId)->whereKey($orderId)->firstOrFail();

        $this->dispatch('order-open', visitId: $this->visitId, orderId: $orderId);
    }

    /** @return Collection<int,Order> */
    #[Computed]
    public function orders(): Collection
    {
        return Order::query()
            ->where('visit_id', $this->visitId)
            ->when($this->filter !== '', fn ($q) => $q->where('type', $this->filter))
            ->with(['items', 'assignee', 'department'])
            ->latest('id')
            ->get();
    }

    /** How many of each type sit on this visit, for the filter chips. */
    #[Computed]
    public function counts(): array
    {
        $counts = Order::query()->where('visit_id', $this->visitId)
            ->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->all();

        $out = ['' => array_sum($counts)];
        foreach (OrderType::cases() as $case) {
            $out[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $out;
    }

    /** A <livewire:ui.select-search> child reports a pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = $id;

        // Narrowing to a ward strands a bed in another one: the box beside it
        // would refuse to find the bed still showing in it.
        if ($name === 'ward_id') {
            $this->dropBedOutsideWard();
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = null;

        // Clearing the ward WIDENS the bed box back to the whole hospital,
        // which the chosen bed is certainly in — so it keeps its pick.
    }

    private function dropBedOutsideWard(): void
    {
        if ($this->bed_id === null || $this->ward_id === null) {
            return;
        }

        $stillThere = \App\Models\Bed::whereKey($this->bed_id)
            ->where('ward_id', $this->ward_id)->exists();

        if (! $stillThere) {
            $this->bed_id = null;
        }

        $this->formNonce++;
    }

    /**
     * The catalogue for whichever type is being ordered, narrowed by `pick`
     * and capped. Anything already ticked is kept in the list even when it
     * falls outside the search, so narrowing never silently drops a choice
     * the user has already made.
     *
     * Returned as plain rows, not models: the picker needs an id, a name and a
     * price, and two different models answering one list is exactly the sort of
     * thing that ends up with a variance problem for no gain.
     *
     * @return list<array{id:int,name:string,price:string}>
     */
    #[Computed]
    public function catalogue(): array
    {
        $term = trim($this->pick);
        $chosen = $this->type === OrderType::Imaging->value ? $this->study_ids : $this->test_ids;

        $query = match ($this->type) {
            OrderType::Lab->value => \App\Models\LabTest::query()->select(['id', 'name', 'price']),
            OrderType::Imaging->value => \App\Models\RadiologyStudy::query()->select(['id', 'name', 'price']),
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query->where('is_active', true)
            ->when($term !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', '%'.$term.'%')
                ->orWhereIn('id', $chosen)))
            ->orderBy('name')
            ->limit(self::PICK_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'price' => (string) $row->price,
            ])
            ->values()->all();
    }

    /** How many rows the catalogue holds in total, so the cap can be honest. */
    #[Computed]
    public function catalogueTotal(): int
    {
        return match ($this->type) {
            OrderType::Lab->value => \App\Models\LabTest::where('is_active', true)->count(),
            OrderType::Imaging->value => \App\Models\RadiologyStudy::where('is_active', true)->count(),
            default => 0,
        };
    }

    /**
     * How this piece of work is usually written down.
     *
     * What this hospital has ACTUALLY written for this kind comes first —
     * a hospital that says "Review BP" should be offered "Review BP" — topped
     * up from the curated set so a fresh hospital is not staring at an empty
     * row. Only the kinds that take a free-text title have any: lab, imaging
     * and pharmacy name a catalogue row instead, and a phrase there would
     * compete with the picker rather than help it.
     *
     * @return list<string>
     */
    #[Computed]
    public function titleSuggestions(): array
    {
        $curated = SampleCatalogue::orderTitles()[$this->type] ?? null;
        if ($curated === null) {
            return [];
        }

        $used = Order::query()
            ->where('type', $this->type)
            ->whereNotNull('title')
            // Carried-over orders had their titles generated by the migration
            // ("Consultation — 2 items", a drug name typed as a consultation
            // because its charge had no recognisable prefix). Learning phrasing
            // from those would teach the hospital its own import artefacts.
            ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', '!=', Order::CARRIED_OVER))
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw('title, count(*) as n')
            ->groupBy('title')->orderByDesc('n')
            ->limit(self::TITLE_LIMIT)
            ->pluck('title')->all();

        // Case-insensitive, so "Wound dressing" and "wound dressing" are not
        // offered as two different things.
        $seen = array_map('mb_strtolower', $used);
        foreach ($curated as $phrase) {
            if (count($used) >= self::TITLE_LIMIT) {
                break;
            }
            if (! in_array(mb_strtolower($phrase), $seen, true)) {
                $used[] = $phrase;
                $seen[] = mb_strtolower($phrase);
            }
        }

        return $used;
    }

    /** Fill the title from a suggestion. */
    public function useTitle(string $title): void
    {
        if (in_array($title, $this->titleSuggestions, true)) {
            $this->title = $title;
            $this->resetErrorBag('title');
        }
    }

    /** Switching the kind of work resets what only made sense for the last one. */
    public function updatedType(): void
    {
        $this->reset(['title', 'test_ids', 'study_ids', 'ward_id', 'bed_id', 'pick']);
        $this->resetErrorBag();
        unset($this->catalogue, $this->catalogueTotal, $this->titleSuggestions);
    }

    public function updatedPick(): void
    {
        $this->pick = mb_substr(trim($this->pick), 0, 60);
        unset($this->catalogue);
    }

    private function authorizeWrite(): void
    {
        abort_unless(Auth::user()?->can('visits.create') || Auth::user()?->can('visits.manage'), 403);

        // A visit that has been completed or called off is not a place to put
        // new work: an order raised on a cancelled visit bills a patient for an
        // attendance the hospital decided did not happen. Reopening it is a
        // stage change away, which is exactly what that control is for.
        if (! $this->visit->isOpen()) {
            throw new RuntimeException(
                'This visit is '.strtolower($this->visit->stateLabel()).
                    ' — reopen it before adding anything to it.'
            );
        }
    }

    /**
     * Run the guard, and turn the state half of it into a sentence.
     *
     * The permission half still aborts — a reader without it has no business
     * here at all. Being finished is different: the controls for it are
     * already hidden, so reaching this means something changed underneath, and
     * the answer is to say so rather than to throw a 500 at them.
     */
    private function refuseIfFinished(): bool
    {
        try {
            $this->authorizeWrite();

            return true;
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
            unset($this->writable);

            return false;
        }
    }

    /** Whether this visit can still be given work. */
    #[Computed]
    public function writable(): bool
    {
        try {
            $this->authorizeWrite();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function openAdd(): void
    {
        if (! $this->refuseIfFinished()) {
            return;
        }

        $this->reset(['type', 'title', 'assigned_to', 'department_id', 'notes',
            'test_ids', 'study_ids', 'ward_id', 'bed_id', 'pick']);
        $this->type = $this->filter !== '' ? $this->filter : OrderType::Consultation->value;

        // Whoever is raising the order is the likeliest person to do it, and
        // reassigning is one click. Starting empty makes everyone pick
        // themselves by hand, every time.
        $this->assigned_to = Auth::id();

        $this->placed = null;
        $this->formNonce++;
        $this->resetErrorBag();
        unset($this->catalogue, $this->catalogueTotal, $this->titleSuggestions);
        $this->showAdd = true;
    }

    /**
     * Place one order of any kind.
     *
     * The three types with a catalogue behind them go through the service that
     * owns their machinery — LabService, RadiologyService and
     * DispensationService — because that machinery is real: results and
     * reference ranges, findings, and stock that must not go negative. Each of
     * those services already places the order itself, so this only chooses the
     * door. Everything else is a plain order.
     */
    public function add(OrderService $orders): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);

        if (! $this->refuseIfFinished()) {
            $this->showAdd = false;

            return;
        }

        $type = OrderType::tryFrom((string) $this->type);
        if ($type === null) {
            return;
        }

        match ($type) {
            OrderType::Lab => $this->orderLab($visit),
            OrderType::Imaging => $this->orderImaging($visit),
            OrderType::Admission => $this->admitPatient($visit),
            default => $this->placePlain($visit, $type, $orders),
        };
    }

    private function placePlain(Visit $visit, OrderType $type, OrderService $orders): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $orders->place($visit, $type, $data['title'], [
            'assigned_to' => $data['assigned_to'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], Auth::id());

        $this->finish('Order placed.');
    }

    private function orderLab(Visit $visit): void
    {
        abort_unless(Auth::user()?->can('lab.order'), 403);
        $this->validate(['test_ids' => ['required', 'array', 'min:1']]);

        app(\App\Services\LabService::class)->order($visit, $this->test_ids, $this->notes, Auth::id());
        $this->finish('Lab tests ordered.');
    }

    private function orderImaging(Visit $visit): void
    {
        abort_unless(Auth::user()?->can('radiology.order'), 403);
        $this->validate(['study_ids' => ['required', 'array', 'min:1']]);

        app(\App\Services\RadiologyService::class)->order($visit, $this->study_ids, $this->notes, Auth::id());
        $this->finish('Imaging ordered.');
    }

    /**
     * Admitting IS the order.
     *
     * A bed is scarce and exclusive, so it is claimed when the work is raised,
     * not later when someone gets round to adding items — which is exactly why
     * a lab order names its tests at placement too. AdmissionService takes the
     * bed under a lock, opens the admission and raises the order pointing at
     * it, all in one transaction; everything else about the stay is added to
     * that order afterwards, like any other.
     */
    private function admitPatient(Visit $visit): void
    {
        abort_unless(Auth::user()?->can('ipd.manage'), 403);

        $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'bed_id' => ['required', 'integer', 'exists:beds,id'],
        ], [
            'bed_id.required' => 'Choose a bed for the patient.',
        ]);

        try {
            app(\App\Services\AdmissionService::class)->admit(
                $visit->patient,
                \App\Models\Bed::findOrFail($this->bed_id),
                [
                    'visit_id' => $visit->id,
                    'admitting_doctor_id' => $this->assigned_to,
                    'title' => $this->title,
                    'reason' => $this->notes,
                ],
                Auth::id(),
            );
        } catch (RuntimeException $e) {
            $this->addError('bed_id', $e->getMessage());

            return;
        }

        $this->finish('Patient admitted.');
    }

    /**
     * Done. The form empties and the dialog turns into its own confirmation:
     * what happened, and the two things anyone wants next.
     */
    private function finish(string $message): void
    {
        $this->reset(['title', 'assigned_to', 'department_id', 'notes',
            'test_ids', 'study_ids', 'ward_id', 'bed_id', 'pick']);
        $this->resetErrorBag();
        $this->placed = $message;

        unset($this->orders, $this->counts, $this->catalogue, $this->catalogueTotal, $this->titleSuggestions);

        $this->dispatch('toast', message: $message, type: 'success');
        $this->dispatch('visit-updated');
    }

    /**
     * Go again, on a clean form, without hunting for the button.
     *
     * The kind carries over. Someone who has just sent bloods is more often
     * sending more bloods than starting somewhere else, and switching is one
     * click either way.
     */
    public function addAnother(): void
    {
        $keep = $this->type;

        $this->placed = null;
        $this->openAdd();

        $this->type = $keep;
        unset($this->catalogue, $this->catalogueTotal, $this->titleSuggestions);
    }

    /** Finished for now. */
    public function closeAdd(): void
    {
        $this->placed = null;
        $this->showAdd = false;
    }

    /** Move one order along. The legal moves come from the enum. */
    #[On('visit-updated')]
    public function refresh(): void
    {
        unset($this->visit, $this->orders, $this->counts);
    }

    public function render()
    {
        $this->authorize('view', $this->visit);

        return view('livewire.visits.panels.orders');
    }
}
