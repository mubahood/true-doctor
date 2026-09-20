<?php

namespace App\Livewire\FinancialYears;

use App\Exceptions\FinancialYearException;
use App\Http\Requests\FinancialYearRequest;
use App\Livewire\Concerns\ManagesFinancialYears;
use App\Livewire\Concerns\WithTable;
use App\Models\FinancialYear;
use App\Services\FinancialYearService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Accounting periods — searchable table with inline close/reopen + a slide-over
 * to open a new period. Every period transition and the no-overlap invariant
 * live in FinancialYearService (the crown-jewel logic), so this component keeps
 * its own save() instead of CrudModal's generic write: it authorizes, validates
 * with FinancialYearRequest::rulesFor() and reports the service's domain
 * exceptions as toasts. Close/reopen are the ManagesFinancialYears trait,
 * shared verbatim with FinancialYears\Show.
 *
 * @property-read FinancialYear|null $peeked
 * @property-read array{payments_total:string,invoices_total:string,outstanding:string,by_method:array<string,string>,invoice_count:int,payment_count:int}|null $peekedReport
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, ManagesFinancialYears, WithTable;

    public bool $showForm = false;

    public string $name = '';

    public ?string $starts_on = null;

    public ?string $ends_on = null;

    public function mount(): void
    {
        $this->authorize('viewAny', FinancialYear::class);
    }

    public function create(): void
    {
        $this->authorize('manage', FinancialYear::class);
        $this->reset(['name', 'starts_on', 'ends_on']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    protected function rules(): array
    {
        return FinancialYearRequest::rulesFor();
    }

    public function save(FinancialYearService $service): void
    {
        $this->authorize('manage', FinancialYear::class);

        $data = $this->validate();

        try {
            $service->create($data);
        } catch (FinancialYearException|\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->showForm = false;
        $this->reset(['name', 'starts_on', 'ends_on']);
        $this->dispatch('toast', message: 'Financial year created.', type: 'success');
    }

    // ── Reading one without leaving the list ─────────────────────────────

    public bool $showPeek = false;

    public ?int $peekId = null;

    /**
     * One period, over the list rather than instead of it.
     *
     * Nobody opens a financial year to read its dates back — they open it for
     * the roll-up, and that is four figures, not a page.
     */
    public function peek(int $id): void
    {
        $year = FinancialYear::findOrFail($id);
        $this->authorize('view', $year);

        $this->peekId = $year->id;
        unset($this->peeked, $this->peekedReport);
        $this->showPeek = true;
    }

    public function closePeek(): void
    {
        $this->reset(['showPeek', 'peekId']);
    }

    #[Computed]
    public function peeked(): ?FinancialYear
    {
        return $this->peekId === null ? null : FinancialYear::with('closedBy')->find($this->peekId);
    }

    /**
     * The same roll-up the period's own page shows, from the same service —
     * so the dialog and the page can never disagree about a figure.
     *
     * @return array{payments_total:string,invoices_total:string,outstanding:string,by_method:array<string,string>,invoice_count:int,payment_count:int}|null
     */
    #[Computed]
    public function peekedReport(): ?array
    {
        return $this->peeked === null
            ? null
            : app(FinancialYearService::class)->report($this->peeked);
    }

    /** A close or reopen from inside the dialog re-reads what it is showing. */
    protected function afterPeriodTransition(): void
    {
        unset($this->peeked, $this->peekedReport);
    }

    public function render()
    {
        $this->authorize('viewAny', FinancialYear::class);

        $rows = FinancialYear::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderByDesc('starts_on')
            ->paginate($this->perPage);

        return view('livewire.financial-years.index', ['rows' => $rows])->title('Financial years');
    }
}
