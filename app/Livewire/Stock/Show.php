<?php

namespace App\Livewire\Stock;

use App\Enums\StockMovementReason;
use App\Http\Requests\StockAdjustRequest;
use App\Http\Requests\StockReceiveRequest;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Stock item detail (Detail shape, plan §4.4) — replaces admin/stock/show.blade.php
 * and StockItemController@show/receive/adjust/destroy.
 *
 * Both quantity movements are slide-overs whose buttons are pessimistic
 * (house rule 12): the ledger and the balance only change once the server has
 * answered. Every write goes through StockService, the single writer of stock
 * quantities, so the append-only ledger, the balance snapshot, the negative
 * stock refusal and the low-stock notification keep their guarantees. The only
 * exceptions caught are the RuntimeException family StockService raises
 * (InsufficientStockException and the zero-quantity guard); they surface as an
 * inline error under the quantity field, never as a bare \Throwable.
 *
 * Deletion honours the same protection as Stock\Index::assertDeletable(): an
 * item with movement history is audit evidence, so it is deactivated instead.
 *
 * The receive and adjust forms deliberately share the `quantity`/`note`
 * properties — only one slide-over is ever open, and the shared error-bag keys
 * mean the service's refusal lands under whichever quantity input is on screen.
 *
 * @property-read StockItem $item
 * @property-read Collection<int,StockMovement> $movements
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    /** How many ledger rows the page shows (newest first). */
    public const LEDGER_LIMIT = 50;

    #[Locked]
    public int $itemId;

    public bool $showReceive = false;

    public bool $showAdjust = false;

    public string $quantity = '';

    public ?string $unit_cost = null;

    public ?string $note = null;

    public string $reason = '';

    public function mount(string $stock): void
    {
        // Tenant-scoped resolution: another hospital's uuid is a 404, not a 403.
        $model = StockItem::where('uuid', $stock)->firstOrFail();
        $this->authorize('view', $model);

        $this->itemId = $model->id;
        $this->reason = StockMovementReason::AdjustmentIn->value;
    }

    #[Computed]
    public function item(): StockItem
    {
        return StockItem::with('category')->findOrFail($this->itemId);
    }

    /**
     * The newest LEDGER_LIMIT ledger rows. StockMovement is tenant-scoped, so
     * this can never surface another hospital's history.
     *
     * @return Collection<int,StockMovement>
     */
    #[Computed]
    public function movements(): Collection
    {
        return StockMovement::query()
            ->with('createdBy')
            ->where('stock_item_id', $this->itemId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LEDGER_LIMIT)
            ->get();
    }

    // ── Receive ────────────────────────────────────────────────
    public function openReceive(): void
    {
        $this->authorize('update', $this->item);

        $this->resetForms();
        $this->showReceive = true;
    }

    public function receive(StockService $stock): void
    {
        $item = $this->item;
        $this->authorize('update', $item);

        $data = $this->validate(StockReceiveRequest::rulesFor());

        try {
            $stock->receive(
                $item,
                self::amount($data['quantity']),
                filled($data['unit_cost'] ?? null) ? self::amount($data['unit_cost']) : null,
                Auth::id(),
                ($data['note'] ?? null) ?: null,
            );
        } catch (RuntimeException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->afterMovement();
        $this->showReceive = false;
        $this->dispatch('toast', message: 'Stock received.', type: 'success');
    }

    // ── Adjust ─────────────────────────────────────────────────
    public function openAdjust(): void
    {
        $this->authorize('update', $this->item);

        $this->resetForms();
        $this->showAdjust = true;
    }

    public function adjust(StockService $stock): void
    {
        $item = $this->item;
        $this->authorize('update', $item);

        $data = $this->validate(StockAdjustRequest::rulesFor());

        try {
            $stock->adjust(
                $item,
                StockMovementReason::from($data['reason']),
                self::amount($data['quantity']),
                Auth::id(),
                ($data['note'] ?? null) ?: null,
            );
        } catch (RuntimeException $e) {
            // InsufficientStockException: the movement would drive stock negative.
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->afterMovement();
        $this->showAdjust = false;
        $this->dispatch('toast', message: 'Adjustment recorded.', type: 'success');
    }

    // ── Lifecycle ──────────────────────────────────────────────
    /** Take the item out of circulation without destroying its ledger. */
    public function deactivate(): void
    {
        $item = $this->item;
        $this->authorize('delete', $item);

        $item->update(['is_active' => false]);

        unset($this->item);
        $this->dispatch('toast', message: 'Stock item deactivated.', type: 'success');
    }

    /** Archive an item that never moved; one with history is refused (delete-protection). */
    public function archive()
    {
        $item = $this->item;
        $this->authorize('delete', $item);

        try {
            $this->assertDeletable($item);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return null;
        }

        $item->delete();

        session()->flash('success', 'Stock item archived.');

        return $this->redirect(route('admin.stock.index'), navigate: true);
    }

    /** Mirrors Stock\Index::assertDeletable() — movement history is audit evidence. */
    protected function assertDeletable(StockItem $item): void
    {
        if ($item->movements()->exists()) {
            throw new \DomainException('This item has stock movements and cannot be deleted — deactivate it instead.');
        }
    }

    public function render()
    {
        $item = $this->item;
        $this->authorize('view', $item);

        return view('livewire.stock.show', [
            'item' => $item,
            'reasons' => StockAdjustRequest::reasons(),
        ])->title($item->name);
    }

    /** Normalise a validated numeric string to bcmath scale-2. */
    private static function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function resetForms(): void
    {
        $this->reset(['quantity', 'unit_cost', 'note']);
        $this->reason = StockMovementReason::AdjustmentIn->value;
        $this->resetErrorBag();
        $this->showReceive = false;
        $this->showAdjust = false;
    }

    private function afterMovement(): void
    {
        unset($this->item, $this->movements);
        $this->reset(['quantity', 'unit_cost', 'note']);
    }
}
