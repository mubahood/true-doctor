<?php

namespace App\Livewire\Suppliers;

use App\Http\Requests\SupplierRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Suppliers: who the store buys from (procurement, docs/procurement.md).
 *
 * A directory with a create/edit dialog and a quick view that answers the
 * question a row cannot: what has come in from them and what is still open.
 *
 * @property-read Supplier|null $peeked
 * @property-read Collection<int,PurchaseOrder> $peekedOrders
 * @property-read array{orders:int,open:int,received:string,returned:string} $peekedFigures
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?string $contact_person = null;

    public ?string $phone = null;

    public ?string $email = null;

    public ?string $address = null;

    public ?string $tin = null;

    public int|string|null $payment_terms_days = null;

    public ?string $notes = null;

    public bool $is_active = true;

    /** '' = both; 'active' or 'inactive'. */
    #[Url(history: true, except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Supplier::class);
    }

    protected function modelClass(): string
    {
        return Supplier::class;
    }

    protected function formFields(): array
    {
        return ['name', 'contact_person', 'phone', 'email', 'address', 'tin', 'payment_terms_days', 'notes', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Supplier';
    }

    protected function nullableFields(): array
    {
        return ['contact_person', 'phone', 'email', 'address', 'tin', 'payment_terms_days', 'notes'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return SupplierRequest::rulesFor($this->editingId);
    }

    protected function resetsPage(): array
    {
        return ['status'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['name', 'orders_count', 'is_active'];
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    protected function assertDeletable(Model $model): void
    {
        if ($model instanceof Supplier && $reason = $model->deletionBlocker()) {
            throw new \DomainException($reason);
        }
    }

    // ── Quick view ─────────────────────────────────────────────────────

    protected function peekModel(): string
    {
        return Supplier::class;
    }

    protected function peekCaches(): array
    {
        return ['peekedOrders', 'peekedFigures'];
    }

    /** @return Collection<int,PurchaseOrder> */
    #[Computed]
    public function peekedOrders(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : PurchaseOrder::where('supplier_id', $this->peekId)->latest('id')->limit(6)->get();
    }

    /** @return array{orders:int,open:int,received:string,returned:string} */
    #[Computed]
    public function peekedFigures(): array
    {
        $supplier = $this->peeked;

        return $supplier instanceof Supplier
            ? $supplier->figures()
            : ['orders' => 0, 'open' => 0, 'received' => '0.00', 'returned' => '0.00'];
    }

    public function render()
    {
        $this->authorize('viewAny', Supplier::class);

        // The same filters as the app's list (Supplier::listed).
        $query = Supplier::query()
            ->withCount(['purchaseOrders as orders_count'])
            ->listed($this->status, $this->search);

        /** @var Builder<Supplier> $sorted */
        $sorted = $this->applySort($query, fn (Builder $q) => $q->orderBy('name'));

        return view('livewire.suppliers.index', ['rows' => $sorted->paginate($this->perPage)])
            ->title('Suppliers');
    }
}
