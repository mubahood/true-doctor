<?php

namespace App\Livewire\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Livewire\Concerns\WithTable;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProcurementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Purchase orders: what the store has asked suppliers for, and what is still
 * to come (docs/procurement.md). An order is a workspace page; this list
 * finds it and starts a new one.
 *
 * @property-read Collection<int,Supplier> $suppliers
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, WithTable;

    /** '' = all; 'open' = ordered and waiting; or one status. */
    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: '')]
    public string $supplier = '';

    public bool $showCreate = false;

    public string $supplier_id = '';

    public ?string $expected_date = null;

    public ?string $notes = null;

    public function mount(): void
    {
        $this->authorize('viewAny', PurchaseOrder::class);
    }

    protected function resetsPage(): array
    {
        return ['status', 'supplier'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['po_no', 'order_date', 'expected_date', 'total', 'status'];
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->supplier !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'supplier']);
        $this->resetPage();
    }

    /** @return Collection<int,Supplier> */
    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->orderBy('name')->limit(300)->get(['id', 'name', 'is_active']);
    }

    public function openCreate(): void
    {
        $this->authorize('create', PurchaseOrder::class);
        $this->reset(['supplier_id', 'expected_date', 'notes']);
        $this->resetErrorBag();
        $this->showCreate = true;
    }

    /** A new draft, then straight to its page to add what is being ordered. */
    public function createOrder(ProcurementService $procurement): void
    {
        $this->authorize('create', PurchaseOrder::class);

        $data = $this->validate([
            'supplier_id' => ['required', 'integer'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $supplier = Supplier::query()->find((int) $data['supplier_id']);
        if ($supplier === null) {
            $this->addError('supplier_id', 'Choose a supplier.');

            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $order = $procurement->createOrder($supplier, [
            'expected_date' => $data['expected_date'] ?: null,
            'notes' => $data['notes'] ?? null,
        ], [], $user);

        session()->flash('success', $order->po_no.' started. Add what you are ordering.');
        $this->redirectRoute('admin.purchase-orders.show', $order, navigate: true);
    }

    public function render()
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::query()
            ->with('supplier')
            ->withCount('items')
            ->listed($this->status, $this->supplier, $this->search);

        /** @var Builder<PurchaseOrder> $sorted */
        $sorted = $this->applySort($query, fn (Builder $q) => $q->latest('id'));

        return view('livewire.purchase-orders.index', [
            'rows' => $sorted->paginate($this->perPage),
            'statuses' => PurchaseOrderStatus::options(),
        ])->title('Purchase orders');
    }
}
