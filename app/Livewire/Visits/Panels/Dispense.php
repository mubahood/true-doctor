<?php

namespace App\Livewire\Visits\Panels;

use App\Exceptions\InsufficientStockException;
use App\Http\Requests\DispensationRequest;
use App\Models\Dispensation;
use App\Models\StockItem;
use App\Services\DispensationService;
use App\Support\HospitalSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dispensing against the visit — a real multi-line repeater (plan D2). The
 * classic page hardcoded `items[0][…]`: array notation that only ever submitted
 * one line, over a <select> that rendered every stock item in the hospital
 * (plan D3). Here each row picks its drug through <livewire:ui.select-search
 * resource="stock-items"> (tenant-scoped, 20 rows a keystroke, on-hand + price
 * in the meta line) and rows are added and removed client-side.
 *
 * DispensationService deducts stock and bills the drug in ONE transaction, so a
 * shortfall on the third row rolls the whole hand-out back; the resulting
 * InsufficientStockException is reported inline on the offending row instead of
 * a flash + reload. The new charges wake the Charges panel via `visit-updated`.
 */
#[Lazy]
class Dispense extends Component
{
    use AuthorizesRequests, InteractsWithVisit;

    /**
     * Repeater rows: {uid, stock_item_id, quantity}. `uid` keys the row (and its
     * picker) stably across add/remove; it carries no rule and never reaches the
     * service. Typed loosely on purpose — the client owns this array, so every
     * read is guarded and DispensationRequest::rulesFor() is the gate before it
     * reaches the service.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $items = [];

    public ?string $note = null;

    /**
     * The panel lists what has been dispensed; dispensing opens a dialog. An
     * open repeater on the page made the visit read as a form.
     */
    public bool $showForm = false;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;

        $this->authorize('view', $this->visit);
        $this->authorizeDispense();

        $this->items = [self::blankRow()];
    }

    private function authorizeDispense(): void
    {
        abort_unless(Auth::user()?->can('pharmacy.dispense'), 403);
    }

    /** @return array{uid: string, stock_item_id: null, quantity: string} */
    private static function blankRow(): array
    {
        return ['uid' => (string) Str::uuid(), 'stock_item_id' => null, 'quantity' => ''];
    }

    // ── Repeater ───────────────────────────────────────────────

    public function openForm(): void
    {
        $this->authorizeDispense();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function addRow(): void
    {
        $this->items[] = self::blankRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->items = [self::blankRow()];
        }

        $this->resetErrorBag();
    }

    /** A row's <livewire:ui.select-search> reports its pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        $index = $this->rowFor($name);
        if ($index !== null) {
            $this->items[$index]['stock_item_id'] = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        $index = $this->rowFor($name);
        if ($index !== null) {
            $this->items[$index]['stock_item_id'] = null;
        }
    }

    /** Picker names are namespaced by row uid, so events never land on the wrong row. */
    private function rowFor(string $name): ?int
    {
        foreach ($this->items as $index => $row) {
            if ('dispense-'.(string) ($row['uid'] ?? '') === $name) {
                return $index;
            }
        }

        return null;
    }

    // ── Data ───────────────────────────────────────────────────

    /** @return Collection<int, Dispensation> */
    #[Computed]
    public function dispensations(): Collection
    {
        return $this->visit->dispensations()->with('items')->latest()->get();
    }

    // ── Dispense & bill ────────────────────────────────────────

    public function dispense(DispensationService $service): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);
        $this->authorizeDispense();

        $rules = DispensationRequest::rulesFor();
        unset($rules['prescription_id']);           // this form never links a prescription

        if (is_string($this->note) && trim($this->note) === '') {
            $this->note = null;
        }

        $data = $this->validate($rules);

        $lines = array_map(fn (array $row) => [
            'stock_item_id' => (int) $row['stock_item_id'],
            'quantity' => (string) $row['quantity'],
        ], array_values($data['items']));

        try {
            $service->dispense($visit, $lines, $data['note'] ?? null, Auth::id());
        } catch (InsufficientStockException $e) {
            $this->addError('items.'.$this->shortfallRow().'.quantity', $e->getMessage());

            return;
        }

        $this->items = [self::blankRow()];
        $this->note = null;
        unset($this->visit, $this->dispensations);

        $this->showForm = false;

        $this->dispatch('toast', message: 'Dispensed and billed.', type: 'success');
        $this->dispatch('visit-updated');
    }

    /**
     * Which row the shortfall came from. The transaction rolled back, so the
     * on-hand quantities are exactly what they were when the service refused.
     */
    private function shortfallRow(): int
    {
        foreach ($this->items as $index => $row) {
            $item = StockItem::find($row['stock_item_id'] ?? null);
            if ($item === null || ! is_numeric($row['quantity'] ?? null)) {
                continue;
            }
            if (bccomp((string) $item->current_quantity, HospitalSettings::decimal($row['quantity'], 2), 2) < 0) {
                return $index;
            }
        }

        return 0;
    }

    public function render()
    {
        $this->authorize('view', $this->visit);
        $this->authorizeDispense();

        return view('livewire.visits.panels.dispense');
    }
}
