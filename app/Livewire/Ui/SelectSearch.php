<?php

namespace App\Livewire\Ui;

use App\Enums\BedStatus;
use App\Models\Bed;
use App\Models\Department;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Support\HospitalSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Async typeahead picker (docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md §4.4; C4, D3, L1).
 *
 * Replaces the `<select>`s that used to render *every* patient / open invoice /
 * available bed on every render of a parent component. The parent keeps owning
 * the foreign key; this child only searches and reports the pick:
 *
 *   <livewire:ui.select-search resource="patients" name="patient_id"
 *                              :selected="$patient_id" placeholder="Search patients…" />
 *
 *   #[On('select-search:picked')]
 *   public function picked(string $name, int $id): void { … }
 *
 * Every query runs through the tenant global scope (users through
 * User::currentHospital()), so a pick can never cross hospitals, and every
 * result set is capped at RESULT_LIMIT rows.
 */
/**
 * @property-read list<array{id:int,label:string,meta:string}> $options
 * @property-read list<array{id:int,label:string,meta:string}> $suggestions
 * @property-read array{likely:list<array{id:int,label:string,meta:string}>,rest:list<array{id:int,label:string,meta:string}>} $browse
 */
class SelectSearch extends Component
{
    /** Whitelisted resources — anything else is refused at mount. */
    public const RESOURCES = ['patients', 'visits', 'doctors', 'staff', 'invoices-open', 'beds-available', 'departments', 'rooms', 'providers', 'stock-items', 'services', 'wards'];

    private const RESULT_LIMIT = 20;

    /**
     * How many rows to show on focus, before anyone types.
     *
     * Deliberately larger than a search page: browsing is what this list is
     * for. It is still a cap, and still one query — the thing it replaces was
     * a <select> that rendered every row in the table on every render.
     */
    private const BROWSE_LIMIT = 50;

    /** A handful of likely picks, offered before anyone types. */
    private const SUGGEST_LIMIT = 5;

    #[Locked]
    public string $resource = 'patients';

    /** The parent property this picker feeds (e.g. 'patient_id'). */
    #[Locked]
    public string $name = '';

    #[Locked]
    public ?int $selected = null;

    /**
     * Narrows the resource to one parent — a department for `doctors` and
     * `staff`, a ward for `beds-available`.
     *
     * This is what makes a pair of pickers cascade: choose Paediatrics and the
     * doctor box stops offering the whole hospital. #[Locked] like the rest,
     * so the browser cannot widen its own search.
     */
    #[Locked]
    public ?int $scope = null;

    public string $placeholder = 'Search…';

    public string $query = '';

    /** Memo for scopeIsEmpty(), which is asked more than once per render. */
    private ?bool $emptyScope = null;

    /**
     * Whether the list is showing. Server-side rather than a CSS toggle
     * because the rows are not in the DOM until they are wanted: fifty rows
     * per picker per render, on a page with four of them, is exactly the waste
     * this component exists to avoid.
     */
    public bool $open = false;

    /** Label of the current pick, resolved through the same tenant-scoped query. */
    public ?string $selectedLabel = null;

    public function mount(string $resource, string $name, ?int $selected = null, ?string $placeholder = null, ?int $scope = null): void
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404, 'Unknown picker resource.');

        $this->resource = $resource;
        $this->name = $name;
        $this->selected = $selected;
        $this->scope = $scope;
        $this->placeholder = $placeholder ?? 'Search…';
        $this->selectedLabel = $selected !== null ? $this->labelFor($selected) : null;
    }

    public function updatedQuery(): void
    {
        $this->query = mb_substr(trim($this->query), 0, 100);
        $this->open = true;
        unset($this->browse);
    }

    /** Focus opens the list; nobody should have to type to see what there is. */
    public function openList(): void
    {
        $this->open = true;
    }

    public function closeList(): void
    {
        $this->open = false;
    }

    /**
     * Tenant-scoped, capped result set for the current query.
     *
     * @return list<array{id: int, label: string, meta: string}>
     */
    #[Computed]
    public function options(): array
    {
        return $this->rows($this->query, null);
    }

    /**
     * Does the chosen scope hold nobody at all?
     *
     * Only `doctors` narrows through another table, and only that narrowing
     * can empty the list of everything. Memoised for the request: it is asked
     * once per query and once per empty-state render.
     */
    public function scopeIsEmpty(): bool
    {
        if ($this->scope === null || $this->resource !== 'doctors') {
            return false;
        }

        return $this->emptyScope ??= ! User::currentHospital()
            ->where('role', 'doctor')
            ->whereHas('staffProfile', fn ($p) => $p->where('department_id', $this->scope))
            ->exists();
    }

    /**
     * What there is, before anyone types.
     *
     * The likely picks lead — the same real signals the pills use — and the
     * rest follow in the resource's own order. So the first thing a reader
     * sees on focus is the answer they probably wanted, and the list beneath
     * it is there to browse rather than to search.
     *
     * @return array{likely: list<array{id:int,label:string,meta:string}>, rest: list<array{id:int,label:string,meta:string}>}
     */
    #[Computed]
    public function browse(): array
    {
        if (! $this->open || $this->query !== '' || $this->selected !== null) {
            return ['likely' => [], 'rest' => []];
        }

        $likely = $this->suggestions;
        $seen = array_column($likely, 'id');

        $rest = array_values(array_filter(
            $this->rows('', null, self::BROWSE_LIMIT),
            fn (array $row) => ! in_array($row['id'], $seen, true),
        ));

        return ['likely' => $likely, 'rest' => $rest];
    }

    /**
     * What is probably wanted, before anyone types.
     *
     * Not the first five alphabetically — that is a list, not a suggestion.
     * Each resource answers with a real signal: the person signed in, the
     * departments work actually goes to, the drugs actually dispensed, the
     * patients actually seen. Cheap, capped, and skipped entirely once
     * something is picked or typed.
     *
     * @return list<array{id: int, label: string, meta: string}>
     */
    #[Computed]
    public function suggestions(): array
    {
        if ($this->selected !== null || $this->query !== '') {
            return [];
        }

        $ids = match ($this->resource) {
            'staff', 'doctors' => $this->mePlusRecentColleagues(),
            'departments' => $this->busiest('department_id'),
            'stock-items' => $this->mostDispensed(),
            'services' => $this->mostCharged(),
            'wards' => $this->wardsWithRoom(),
            'patients' => $this->recentlySeen(),
            default => [],
        };

        if ($ids === []) {
            return [];
        }

        // Resolved through the same tenant-scoped query as everything else, so
        // a suggestion can no more cross hospitals than a search result can.
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->rows('', (int) $id)[0] ?? null;
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<int> */
    private function mePlusRecentColleagues(): array
    {
        $me = Auth::id();
        $mine = $this->resource === 'doctors' && Auth::user()?->role !== 'doctor' ? [] : array_filter([$me]);

        $others = User::currentHospital()
            ->where('is_active', true)
            ->when($this->resource === 'doctors', fn ($q) => $q->where('role', 'doctor'))
            ->when($this->scope !== null, fn ($q) => $q->whereHas('staffProfile',
                fn ($p) => $p->where('department_id', $this->scope)))
            ->when($me !== null, fn ($q) => $q->whereKeyNot($me))
            ->latest('id')
            ->limit(self::SUGGEST_LIMIT - count($mine))
            ->pluck('id')->all();

        return array_map('intval', array_merge($mine, $others));
    }

    /** Where the work has actually been going lately. @return list<int> */
    private function busiest(string $column): array
    {
        return Order::query()
            ->whereNotNull($column)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("{$column} as id, count(*) as n")
            ->groupBy($column)->orderByDesc('n')
            ->limit(self::SUGGEST_LIMIT)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function mostDispensed(): array
    {
        return OrderItem::query()
            ->whereNotNull('stock_item_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('stock_item_id as id, count(*) as n')
            ->groupBy('stock_item_id')->orderByDesc('n')
            ->limit(self::SUGGEST_LIMIT)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** What the hospital actually charges for, not the top of the price list. @return list<int> */
    private function mostCharged(): array
    {
        return OrderItem::query()
            ->whereNotNull('service_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('service_id as id, count(*) as n')
            ->groupBy('service_id')->orderByDesc('n')
            ->limit(self::SUGGEST_LIMIT)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Somewhere to actually put the patient. @return list<int> */
    private function wardsWithRoom(): array
    {
        return Ward::query()
            ->where('is_active', true)
            ->whereHas('beds', fn ($q) => $q
                ->where('status', BedStatus::Available->value)->where('is_active', true))
            ->orderBy('name')
            ->limit(self::SUGGEST_LIMIT)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function recentlySeen(): array
    {
        return Visit::query()
            ->latest('id')
            ->limit(self::SUGGEST_LIMIT * 3)
            ->pluck('patient_id')->unique()->take(self::SUGGEST_LIMIT)
            ->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * One tenant-scoped query per whitelisted resource. Either a search page
     * ($term) or a single-row resolution ($onlyId) — never both.
     *
     * @return list<array{id: int, label: string, meta: string}>
     */
    private function rows(string $term, ?int $onlyId, ?int $cap = null): array
    {
        $like = '%'.$term.'%';
        $limit = $onlyId !== null ? 1 : ($cap ?? self::RESULT_LIMIT);
        $pinned = $onlyId !== null;
        $searching = ! $pinned && $term !== '';

        return match ($this->resource) {
            'patients' => Patient::query()
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('patient_no', 'like', $like)
                    ->orWhere('phone_1', 'like', $like)))
                ->orderBy('first_name')->orderBy('last_name')
                ->limit($limit)
                ->get(['id', 'first_name', 'last_name', 'patient_no'])
                ->map(fn (Patient $p) => ['id' => (int) $p->id, 'label' => trim($p->first_name.' '.$p->last_name), 'meta' => (string) $p->patient_no])
                ->values()->all(),

            // NARROWED, BUT NEVER TO NOTHING.
            //
            // A department narrows this list through `staff_profiles`, and a
            // hospital that has not filled those in — which is every hospital
            // on its first day — got an EMPTY doctor box the moment a
            // department was chosen, with no way to book at all. A filter that
            // silently hides everybody is worse than no filter.
            //
            // So the narrowing applies only when the department actually has
            // doctors in it; otherwise the whole hospital is offered and the
            // list says why (`scopeIsEmpty`).
            'doctors' => User::currentHospital()
                ->where('role', 'doctor')
                ->when($this->scope !== null && ! $this->scopeIsEmpty(), fn ($q) => $q->whereHas('staffProfile',
                    fn ($p) => $p->where('department_id', $this->scope)))
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)))
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => (int) $u->id, 'label' => (string) $u->name, 'meta' => (string) $u->email])
                ->values()->all(),

            // Anyone who works here, not only doctors: a lab test is assigned
            // to a technician, an admission to a nurse.
            'staff' => User::currentHospital()
                ->where('is_active', true)
                ->when($this->scope !== null, fn ($q) => $q->whereHas('staffProfile',
                    fn ($p) => $p->where('department_id', $this->scope)))
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('role', 'like', $like)))
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'email', 'role'])
                ->map(fn (User $u) => [
                    'id' => (int) $u->id,
                    'label' => (string) $u->name,
                    'meta' => (string) ($u->role_label ?? $u->role),
                ])
                ->values()->all(),

            // Open visits, for anything arranged DURING an attendance — a
            // follow-up booked at the end of a consultation belongs to the
            // visit that prompted it, not to a patient picked out of the
            // register (docs/visits.md).
            'visits' => Visit::query()
                ->with('patient:id,first_name,last_name,patient_no')
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when(! $pinned, fn ($q) => $q->whereIn('status', [
                    \App\Enums\VisitStatus::Pending->value,
                    \App\Enums\VisitStatus::Ongoing->value,
                ]))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('visit_no', 'like', $like)
                    ->orWhereHas('patient', fn ($p) => $p->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('patient_no', 'like', $like))))
                ->latest('id')
                ->limit($limit)
                ->get(['id', 'visit_no', 'patient_id', 'created_at'])
                ->map(fn (Visit $v) => [
                    'id' => (int) $v->id,
                    'label' => trim(($v->patient === null ? 'Unknown' : $v->patient->full_name).' · '.$v->visit_no),
                    'meta' => $v->created_at?->format('d M Y H:i') ?? '',
                ])
                ->values()->all(),

            'invoices-open' => Invoice::query()
                ->whereIn('status', ['issued', 'partially_paid'])
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('invoice_no', 'like', $like)
                    ->orWhereHas('patient', fn ($p) => $p->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('patient_no', 'like', $like))))
                ->latest()
                ->limit($limit)
                ->get(['id', 'invoice_no', 'patient_id', 'balance'])
                ->map(fn (Invoice $i) => ['id' => (int) $i->id, 'label' => (string) $i->invoice_no, 'meta' => HospitalSettings::money($i->balance).' due'])
                ->values()->all(),

            'beds-available' => Bed::query()
                ->with('ward')
                ->where('status', BedStatus::Available->value)
                ->where('is_active', true)
                ->when($this->scope !== null, fn ($q) => $q->where('ward_id', $this->scope))
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('name', 'like', $like)
                    ->orWhereHas('ward', fn ($w) => $w->where('name', 'like', $like))))
                ->orderBy('ward_id')->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(fn (Bed $b) => [
                    'id' => (int) $b->id,
                    'label' => trim(($b->ward?->name ? $b->ward->name.' · ' : '').$b->name),
                    'meta' => HospitalSettings::money($b->daily_charge).'/night',
                ])
                ->values()->all(),

            'departments' => Department::query()
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('name', 'like', $like)->orWhere('code', 'like', $like)))
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'code'])
                ->map(fn (Department $d) => ['id' => (int) $d->id, 'label' => (string) $d->name, 'meta' => (string) ($d->code ?? '')])
                ->values()->all(),

            'rooms' => Room::query()
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where('name', 'like', $like))
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'type'])
                ->map(fn (Room $r) => ['id' => (int) $r->id, 'label' => (string) $r->name, 'meta' => $r->type->label()])
                ->values()->all(),

            'providers' => InsuranceProvider::query()
                ->where('is_active', true)
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('name', 'like', $like)->orWhere('code', 'like', $like)))
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'code'])
                ->map(fn (InsuranceProvider $p) => ['id' => (int) $p->id, 'label' => (string) $p->name, 'meta' => (string) ($p->code ?? '')])
                ->values()->all(),

            // Dispensable stock: active items that still have units on hand. The
            // meta line carries the on-hand quantity and the sale price so the
            // pharmacist never needs the old whole-catalogue <select> (plan D3).
            // The price list. Only what is sellable — an inactive entry is one
            // the hospital has stopped offering, not one to be found in a search.
            'services' => Service::query()
                ->where('is_active', true)
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('name', 'like', $like)->orWhere('code', 'like', $like)))
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'code', 'price'])
                ->map(fn (Service $s) => [
                    'id' => (int) $s->id,
                    'label' => (string) $s->name,
                    'meta' => trim(($s->code ? $s->code.' · ' : '').HospitalSettings::money($s->price)),
                ])
                ->values()->all(),

            // Where a bed is, before which bed. Choosing one narrows the other.
            'wards' => Ward::query()
                ->where('is_active', true)
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where('name', 'like', $like))
                ->withCount(['beds as free_beds_count' => fn ($q) => $q
                    ->where('status', BedStatus::Available->value)->where('is_active', true)])
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(function (Ward $ward) {
                    $free = (int) $ward->getAttribute('free_beds_count');

                    return [
                        'id' => (int) $ward->id,
                        'label' => (string) $ward->name,
                        'meta' => $free === 1 ? '1 bed free' : $free.' beds free',
                    ];
                })
                ->values()->all(),

            'stock-items' => StockItem::query()
                ->where('is_active', true)
                ->where('current_quantity', '>', 0)
                ->when($pinned, fn ($q) => $q->whereKey($onlyId))
                ->when($searching, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('name', 'like', $like)->orWhere('sku', 'like', $like)))
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'unit', 'sale_price', 'current_quantity'])
                ->map(fn (StockItem $s) => [
                    'id' => (int) $s->id,
                    'label' => (string) $s->name,
                    'meta' => trim(self::trimZeros((string) $s->current_quantity).' '.$s->unit).' · '.HospitalSettings::money($s->sale_price),
                ])
                ->values()->all(),

            default => [],
        };
    }

    /** "40.00" → "40", "2.50" → "2.5" — quantities read as quantities, not money. */
    private static function trimZeros(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** Resolve one id through the same tenant-scoped query; null when out of reach. */
    private function labelFor(int $id): ?string
    {
        return $this->rows('', $id)[0]['label'] ?? null;
    }

    /** Pick a row by id — refused unless the id is reachable in this tenant. */
    public function pick(int $id): void
    {
        $label = $this->labelFor($id);
        if ($label === null) {
            return;
        }

        $this->selected = $id;
        $this->selectedLabel = $label;
        $this->query = '';
        $this->open = false;
        unset($this->options, $this->browse);

        $this->dispatch('select-search:picked', name: $this->name, id: $id, label: $label);
    }

    /** Keyboard: Enter picks the first result. */
    public function pickFirst(): void
    {
        $first = $this->options[0] ?? null;
        if ($first !== null) {
            $this->pick($first['id']);
        }
    }

    /** Keyboard: Escape clears the search box (and therefore the result list). */
    /** Escape empties the box first, and closes the list on a second press. */
    public function clearQuery(): void
    {
        if ($this->query === '') {
            $this->open = false;
        }

        $this->query = '';
        unset($this->options, $this->browse);
    }

    /** Drop the current pick and go back to searching. */
    public function change(): void
    {
        $this->selected = null;
        $this->selectedLabel = null;
        $this->query = '';
        // Straight back into a list: whoever pressed "change" wants a
        // different one, and making them type first helps nobody.
        $this->open = true;
        unset($this->options, $this->browse);

        $this->dispatch('select-search:cleared', name: $this->name);
    }

    public function render()
    {
        return view('livewire.ui.select-search');
    }
}
