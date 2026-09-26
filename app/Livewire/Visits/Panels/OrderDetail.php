<?php

namespace App\Livewire\Visits\Panels;

use App\Enums\OrderStatus;
use App\Models\Admission;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\StockItem;
use App\Services\OrderAttachmentService;
use App\Services\OrderDesk;
use App\Services\OrderService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

/**
 * One order, opened to be worked on — see docs/orders.md.
 *
 * Not a read-out. This is where the three things an order accumulates are put
 * in: what it used (its items, which are the bill), what was found (the report
 * and its files), and where it has got to. Everything saves as it is done —
 * there is no Save button, because there is nothing one could mean.
 *
 * It is a component of its own rather than more weight on the Orders list: the
 * list is read on every visit dialog open, and file-upload state has no
 * business riding along with it.
 *
 * @property-read Order|null $order
 * @property-read string $lineTotal
 * @property-read string|null $pickedPrice
 * @property-read string|null $stockOnHand
 * @property-read bool $canManage
 * @property-read Admission|null $stay
 * @property-read array{ready:bool,blocker:?string} $completable
 * @property-read bool $editable
 * @property-read array{lines:list<array{name:string,quantity:string,amount:string}>,stock:list<array{name:string,quantity:string,unit:string}>,total:string} $plan
 */
class OrderDetail extends Component
{
    use AuthorizesRequests, InteractsWithVisit, WithFileUploads;

    /** The dialog entangles a BOOLEAN, so the id lives beside it, never in it. */
    public bool $show = false;

    public ?int $orderId = null;

    /** 'service' — off the price list. 'product' — off the pharmacy shelf. */
    public string $itemKind = 'service';

    public ?int $service_id = null;

    public ?int $stock_item_id = null;

    public string $qty = '1';

    /** Why this line is on the order, in the words of whoever put it there. */
    public ?string $itemNote = null;

    /** The line being corrected, if any, and what it is being corrected to. */
    public ?int $editingId = null;

    public string $editQty = '1';

    public ?string $editNote = null;

    /** What was found. Autosaved as it is typed; never a Save button. */
    public ?string $report = null;

    /** Discharge notes, when finishing an inpatient stay. */
    public ?string $notes = null;

    public ?string $reportSavedAt = null;

    /** @var array<int,mixed> */
    public $files = [];

    /** The dialog becomes its own cancel confirmation rather than a third layer. */
    public bool $confirming = false;

    public ?string $cancelReason = null;

    /** Bumped on every open and every pick, so the child pickers remount clean. */
    public int $nonce = 0;

    /** The pickers a <livewire:ui.select-search> child may feed here. */
    private const PICKERS = ['service_id', 'stock_item_id'];

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
    }

    // ── Opening and closing ──────────────────────────────────────────────

    #[On('order-open')]
    public function open(int $visitId, int $orderId): void
    {
        if ($visitId !== $this->visitId) {
            return;
        }

        $this->authorize('view', $this->visit);

        /** @var Order $order */
        $order = Order::where('visit_id', $this->visitId)->whereKey($orderId)->firstOrFail();

        $this->orderId = $order->id;
        $this->report = $order->report;
        $this->reportSavedAt = $order->report_updated_at?->diffForHumans();

        $this->resetForm();
        $this->cancelEdit();
        $this->confirming = false;
        $this->cancelReason = null;
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->orderId = null;
        $this->confirming = false;
        $this->reset(['report', 'reportSavedAt', 'cancelReason']);
        $this->resetForm();
        $this->cancelEdit();
    }

    #[Computed]
    public function order(): ?Order
    {
        return ! $this->show || $this->orderId === null
            ? null
            : Order::where('visit_id', $this->visitId)
                ->with(['items.stockItem', 'attachments.uploader', 'assignee', 'department', 'requester', 'subject'])
                ->find($this->orderId);
    }

    /**
     * Whether this order could be called done, and what is missing if not.
     *
     * Shown rather than discovered: a button that refuses when pressed teaches
     * people to distrust the screen. The same rule the service enforces
     * (Order::hasEvidence) decides what appears here.
     *
     * @return array{ready:bool,blocker:?string}
     */
    #[Computed]
    public function completable(): array
    {
        $order = $this->order;

        if ($order === null) {
            return ['ready' => false, 'blocker' => null];
        }

        return $order->hasEvidence()
            ? ['ready' => true, 'blocker' => null]
            : ['ready' => false, 'blocker' => 'Add what it used, write a report, or attach a result first.'];
    }

    /**
     * Whether this reader may move the work along at all.
     *
     * Separate from `editable`: an invoiced visit freezes its charges, but the
     * work itself still has to be marked done.
     */
    #[Computed]
    public function canManage(): bool
    {
        return $this->canWrite();
    }

    /** Whether anything on this order may still be changed. */
    #[Computed]
    public function editable(): bool
    {
        $order = $this->order;

        return $order !== null
            && $this->canWrite()
            && app(OrderService::class)->itemsEditable($order);
    }

    // ── What it used ─────────────────────────────────────────────────────

    /** The catalogue price of whatever is currently picked. */
    #[Computed]
    public function pickedPrice(): ?string
    {
        if ($this->itemKind === 'product') {
            $item = $this->stock_item_id === null ? null : StockItem::find($this->stock_item_id);

            return $item === null ? null : (string) $item->sale_price;
        }

        $service = $this->service_id === null ? null : Service::find($this->service_id);

        return $service === null ? null : (string) $service->price;
    }

    /** How much is left on the shelf, so nobody promises what is not there. */
    #[Computed]
    public function stockOnHand(): ?string
    {
        if ($this->itemKind !== 'product' || $this->stock_item_id === null) {
            return null;
        }

        $item = StockItem::find($this->stock_item_id);
        if ($item === null) {
            return null;
        }

        return trim(rtrim(rtrim((string) $item->current_quantity, '0'), '.').' '.$item->unit);
    }

    /** Never typed: price × quantity, in bcmath, like every other line here. */
    #[Computed]
    public function lineTotal(): string
    {
        $price = $this->pickedPrice;
        if ($price === null) {
            return '0.00';
        }

        $qty = \App\Support\HospitalSettings::decimal($this->qty, 2);

        return bccomp($qty, '0', 2) <= 0 ? '0.00' : bcmul($price, $qty, 2);
    }

    /** Switching between the price list and the shelf clears the other pick. */
    public function updatedItemKind(): void
    {
        $this->service_id = null;
        $this->stock_item_id = null;
        $this->nonce++;
        $this->resetErrorBag();
        unset($this->pickedPrice, $this->stockOnHand, $this->lineTotal);
    }

    /** How a <livewire:ui.select-search> child hands its pick back. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = $id;
        unset($this->pickedPrice, $this->stockOnHand, $this->lineTotal);
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (! in_array($name, self::PICKERS, true)) {
            return;
        }

        $this->{$name} = null;
        unset($this->pickedPrice, $this->stockOnHand, $this->lineTotal);
    }

    public function addItem(OrderService $orders): void
    {
        $order = $this->requireWritableOrder();
        if ($order === null) {
            return;
        }

        $this->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'itemNote' => ['nullable', 'string', 'max:255'],
            'service_id' => [$this->itemKind === 'service' ? 'required' : 'nullable', 'integer'],
            'stock_item_id' => [$this->itemKind === 'product' ? 'required' : 'nullable', 'integer'],
        ], [
            'service_id.required' => 'Choose a service.',
            'stock_item_id.required' => 'Choose a product.',
            'qty.gt' => 'Quantity must be more than zero.',
        ]);

        try {
            if ($this->itemKind === 'product') {
                /** @var StockItem $item */
                $item = StockItem::findOrFail($this->stock_item_id);
                $line = $orders->addProductItem($order, $item, $this->qty, Auth::id());
            } else {
                /** @var Service $service */
                $service = Service::findOrFail($this->service_id);
                $line = $orders->addServiceItem($order, $service, $this->qty, Auth::id());
            }

            if (($this->itemNote ?? '') !== '') {
                $line->update(['notes' => trim((string) $this->itemNote)]);
            }
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $this->plainly($e), type: 'error');

            return;
        }

        $this->resetForm();
        $this->refreshOrder();
        $this->dispatch('toast', message: 'Added.', type: 'success');
        $this->dispatch('visit-updated');
    }

    /** Open one line for correction, in place. */
    public function editItem(int $itemId): void
    {
        $order = $this->requireWritableOrder();
        if ($order === null) {
            return;
        }

        /** @var OrderItem $line */
        $line = $order->items()->whereKey($itemId)->firstOrFail();

        if (! $line->status->isBillable()) {
            return;
        }

        $this->editingId = $line->id;
        $this->editQty = $line->tidyQuantity();
        $this->editNote = $line->notes;
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editQty', 'editNote']);
        $this->resetErrorBag();
    }

    public function saveItem(OrderService $orders): void
    {
        $order = $this->requireWritableOrder();
        if ($order === null || $this->editingId === null) {
            return;
        }

        /** @var OrderItem $line */
        $line = $order->items()->whereKey($this->editingId)->firstOrFail();

        $this->validate([
            'editQty' => ['required', 'numeric', 'gt:0'],
            'editNote' => ['nullable', 'string', 'max:255'],
        ], [
            'editQty.gt' => 'Quantity must be more than zero.',
        ]);

        try {
            $orders->updateItem($line, $this->editQty, $this->editNote, Auth::id());
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $this->plainly($e), type: 'error');

            return;
        }

        $this->cancelEdit();
        $this->refreshOrder();
        $this->dispatch('toast', message: 'Line updated.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function removeItem(int $itemId, OrderService $orders): void
    {
        $order = $this->requireWritableOrder();
        if ($order === null) {
            return;
        }

        /** @var OrderItem $line */
        $line = $order->items()->whereKey($itemId)->firstOrFail();

        try {
            $orders->removeItem($line, Auth::id());
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $this->plainly($e), type: 'error');

            return;
        }

        $this->cancelEdit();
        $this->refreshOrder();
        $this->dispatch('toast', message: 'Removed.', type: 'success');
        $this->dispatch('visit-updated');
    }

    // ── What was found ───────────────────────────────────────────────────

    /** Autosave. Typing is the save; the stamp below the box is the receipt. */
    public function updatedReport(OrderService $orders): void
    {
        $order = $this->order;
        if ($order === null || ! $this->editable) {
            return;
        }

        $this->validate(['report' => ['nullable', 'string', 'max:20000']]);

        $orders->saveReport($order, $this->report);

        $this->refreshOrder();
        $this->reportSavedAt = $this->order?->report_updated_at?->diffForHumans();
    }

    /** Dropped or chosen — either way the file is stored the moment it arrives. */
    public function updatedFiles(OrderAttachmentService $attachments): void
    {
        $order = $this->order;
        if ($order === null || ! $this->editable) {
            return;
        }

        $this->validate([
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx,txt,csv'],
        ], [
            'files.*.max' => 'Each file must be 10 MB or smaller.',
            'files.*.mimes' => 'That kind of file cannot be attached.',
        ]);

        $stored = 0;
        foreach ($this->files as $file) {
            $attachments->store($order, $file, Auth::id());
            $stored++;
        }

        $this->reset('files');
        $this->refreshOrder();

        if ($stored > 0) {
            $this->dispatch('toast', message: $stored === 1 ? 'File attached.' : "{$stored} files attached.", type: 'success');
        }
    }

    public function removeAttachment(int $attachmentId, OrderAttachmentService $attachments): void
    {
        $order = $this->requireWritableOrder();
        if ($order === null) {
            return;
        }

        /** @var OrderAttachment $attachment */
        $attachment = $order->attachments()->whereKey($attachmentId)->firstOrFail();
        $attachments->delete($attachment);

        $this->refreshOrder();
        $this->dispatch('toast', message: 'File removed.', type: 'success');
    }

    // ── Where it has got to ──────────────────────────────────────────────

    /**
     * The stay this order is, when it is one.
     */
    #[Computed]
    public function stay(): ?Admission
    {
        $subject = $this->order?->subject;

        return $subject instanceof Admission ? $subject : null;
    }

    public function move(string $status, OrderService $orders): void
    {
        $order = $this->order;
        if ($order === null || ! $this->canWrite()) {
            abort(403);
        }

        $target = OrderStatus::tryFrom($status);
        if ($target === null || $target === OrderStatus::Cancelled) {
            return;   // cancelling goes through the confirmation, never straight
        }

        // OrderDesk decides what "done" means — for a stay, a discharge.
        try {
            $message = app(OrderDesk::class)->move($order, $target, Auth::user(), $this->notes ?: null);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            abort(403);
        } catch (Throwable $e) {
            $this->dispatch('toast', message: $this->plainly($e), type: 'error');

            return;
        }

        $this->refreshOrder();
        $this->dispatch('toast', message: $message, type: 'success');
        $this->dispatch('visit-updated');
    }

    /** Show what cancelling costs before it costs it. */
    public function askCancel(): void
    {
        abort_unless($this->canWrite(), 403);

        $this->cancelReason = null;
        $this->resetErrorBag();
        $this->confirming = true;
    }

    public function keepOrder(): void
    {
        $this->confirming = false;
        $this->cancelReason = null;
    }

    /** What cancelling would undo — money off the bill, goods back on the shelf. */
    #[Computed]
    public function plan(): array
    {
        $order = $this->order;

        return $order === null
            ? ['lines' => [], 'stock' => [], 'total' => '0.00']
            : app(OrderService::class)->reversalPlan($order);
    }

    public function cancelOrder(OrderService $orders): void
    {
        $order = $this->order;
        if ($order === null || ! $this->canWrite()) {
            abort(403);
        }

        $this->validate(['cancelReason' => ['nullable', 'string', 'max:500']]);

        try {
            $orders->transition($order, OrderStatus::Cancelled, Auth::id(), $this->cancelReason ?: null);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->confirming = false;
        $this->cancelReason = null;
        $this->refreshOrder();
        $this->dispatch('toast', message: 'Order cancelled. Its charges are off the bill.', type: 'success');
        $this->dispatch('visit-updated');
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    #[On('visit-updated')]
    public function refreshOrder(): void
    {
        unset($this->visit, $this->order, $this->editable, $this->plan, $this->stay,
            $this->completable, $this->pickedPrice, $this->stockOnHand, $this->lineTotal);
    }

    private function resetForm(): void
    {
        $this->reset(['itemKind', 'service_id', 'stock_item_id', 'qty', 'itemNote']);
        $this->nonce++;
        $this->resetErrorBag();
        unset($this->pickedPrice, $this->stockOnHand, $this->lineTotal);
    }

    private function canWrite(): bool
    {
        return OrderDesk::canWrite(Auth::user());
    }

    /**
     * Every write goes through here: right person, right visit, still open.
     *
     * The first two are security and abort. The third is state — the order was
     * cancelled, or the visit has been invoiced — which the dialog already
     * hides the controls for, so reaching it means something raced and the
     * reader deserves the sentence rather than a 500.
     */
    private function requireWritableOrder(): ?Order
    {
        $order = $this->order;

        abort_if($order === null, 404);
        abort_unless($this->canWrite(), 403);

        try {
            app(OrderService::class)->assertItemsEditable($order);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
            $this->refreshOrder();

            return null;
        }

        return $order;
    }

    /** Domain problems are sentences; anything else is not the reader's business. */
    private function plainly(Throwable $e): string
    {
        // InsufficientStockException is a RuntimeException, so "not enough
        // Amoxicillin in stock" reaches the reader as itself.
        return $e instanceof RuntimeException ? $e->getMessage() : 'That could not be saved.';
    }

    public function render()
    {
        return view('livewire.visits.panels.order-detail');
    }
}
