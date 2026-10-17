<?php

namespace App\Livewire\Imports;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\DataImportService;
use App\Support\CurrentHospital;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One import, start to finish (docs/imports.md): match the columns, watch the
 * check run, choose what to do with rows that look registered, import, and
 * undo within a day. The long steps run a chunk per request, driven by a poll
 * while they work, so closing the page only pauses them.
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public ImportBatch $batch;

    /** @var array<string,string> field => column index ('' for none) */
    public array $map = [];

    #[Url(history: true, except: '')]
    public string $show = '';

    public bool $confirmUndo = false;

    public function mount(ImportBatch $batch): void
    {
        $this->allowed();
        $this->batch = $batch;
        $this->fillMap();
        // Straight to the rows that need a look, once the check is done.
        if ($this->show === '' && $batch->status === ImportStatus::Checked) {
            $this->show = $this->firstWorthLooking();
        }
    }

    private function allowed(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        abort_if(app(CurrentHospital::class)->id() === null, 403);
    }

    private function fillMap(): void
    {
        $this->map = array_map(fn ($i) => $i === null ? '' : (string) $i, $this->batch->column_map ?? []);
    }

    private function firstWorthLooking(): string
    {
        $counts = app(DataImportService::class)->counts($this->batch);

        return match (true) {
            ($counts[ImportRow::DUPLICATE] ?? 0) > 0 => ImportRow::DUPLICATE,
            ($counts[ImportRow::PROBLEM] ?? 0) > 0 => ImportRow::PROBLEM,
            default => '',
        };
    }

    public function paginationView(): string
    {
        return 'livewire.partials.pagination';
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    // ── Steps ─────────────────────────────────────────────────────────

    public function saveMap(DataImportService $imports): void
    {
        $this->allowed();

        try {
            $this->batch = $imports->map($this->batch, $this->map);
        } catch (\RuntimeException $e) {
            $this->addError('map', $e->getMessage());

            return;
        }
        $this->resetErrorBag();
        $this->show = '';
    }

    public function editMap(DataImportService $imports): void
    {
        $this->allowed();

        try {
            $this->batch = $imports->remap($this->batch);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());

            return;
        }
        $this->fillMap();
    }

    /** The next chunk of whatever is running. Called by the poll while it works. */
    public function step(DataImportService $imports): void
    {
        $this->allowed();
        $before = $this->batch->status;
        $this->batch = $imports->step($this->batch, Auth::id());

        if ($before === ImportStatus::Checking && $this->batch->status === ImportStatus::Checked) {
            $this->show = $this->firstWorthLooking();
            $this->resetPage();
        }
        if ($before !== $this->batch->status && in_array($this->batch->status, [ImportStatus::Done, ImportStatus::Undone], true)) {
            $this->show = '';
            $this->dispatch('toast', type: 'success', message: $this->batch->status === ImportStatus::Done ? 'Import finished.' : 'Import undone.');
        }
    }

    public function decide(int $rowId, string $decision, DataImportService $imports): void
    {
        $this->allowed();

        try {
            $imports->decide($this->batch, $rowId, $decision);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());
        }
    }

    public function decideAll(string $decision, DataImportService $imports): void
    {
        $this->allowed();

        try {
            $imports->decideAll($this->batch, $decision);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());
        }
    }

    public function startImport(DataImportService $imports): void
    {
        $this->allowed();

        try {
            $this->batch = $imports->import($this->batch);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());

            return;
        }
        $this->show = '';
        $this->resetPage();
    }

    public function askUndo(): void
    {
        $this->allowed();
        $this->confirmUndo = true;
    }

    public function undo(DataImportService $imports): void
    {
        $this->allowed();
        $this->confirmUndo = false;

        try {
            $this->batch = $imports->undo($this->batch, Auth::id());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());
        }
    }

    public function cancel(DataImportService $imports): mixed
    {
        $this->allowed();

        try {
            $imports->cancel($this->batch);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());

            return null;
        }
        session()->flash('success', 'Import thrown away. Nothing was saved.');

        return $this->redirectRoute('admin.imports.index', navigate: true);
    }

    public function render(DataImportService $imports): View
    {
        $handler = $imports->handler($this->batch->kind);
        $counts = $imports->counts($this->batch);
        $total = max(1, $this->batch->total_rows);
        $status = $this->batch->status;

        $done = match ($status) {
            ImportStatus::Checking => $total - ($counts[ImportRow::PENDING] ?? 0),
            ImportStatus::Importing => $total - ($counts[ImportRow::READY] ?? 0) - ($counts[ImportRow::DUPLICATE] ?? 0),
            ImportStatus::Undoing => $total - ($counts[ImportRow::ADDED] ?? 0),
            default => $total,
        };

        $rows = $status === ImportStatus::Mapping ? null : $this->batch->rows()
            ->when($this->show !== '', fn ($q) => $q->where('status', $this->show))
            ->orderBy('line')
            ->paginate(25);

        // The patients the rows on this page became, in one query, for a link to each.
        $patients = $rows !== null && $this->batch->kind === \App\Enums\ImportKind::Patients
            ? \App\Models\Patient::query()->whereKey(collect($rows->items())->whereIn('status', [ImportRow::ADDED, ImportRow::UPDATED, ImportRow::KEPT])->pluck('record_id')->filter()->all())->get()->keyBy('id')
            : collect();

        return view('livewire.imports.show', [
            'patients' => $patients,
            'fields' => $handler->fields(),
            'decisions' => $handler->decisions(),
            'counts' => $counts,
            'done' => $done,
            'total' => $total,
            'rows' => $rows,
            'preview' => $status === ImportStatus::Mapping ? $this->batch->rows()->orderBy('line')->limit(3)->get() : collect(),
            'undoBlocker' => $imports->undoBlocker($this->batch),
            'imports' => $imports,
        ])->title('Import: '.$this->batch->kind->label());
    }
}
