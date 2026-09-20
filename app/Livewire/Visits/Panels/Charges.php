<?php

namespace App\Livewire\Visits\Panels;

use App\Enums\DiscountType;
use App\Enums\VisitStage;
use App\Http\Requests\InvoiceGenerateRequest;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\Visit;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * The bill so far — and it does not edit a single line of it.
 *
 * Every charge is an item on an order (docs/orders.md), so every charge is
 * owned by a piece of work: the lab order that raised it, the stay that
 * incurred it, the dispensing that took the drugs off the shelf. Cancelling a
 * line from HERE used to be possible, and it quietly desynchronised all three
 * — the drugs never came back, the order kept saying it was completed, and its
 * evidence vanished from under it.
 *
 * So the bill shows, adds and invoices. To change a charge you open the order
 * that owns it, which is one click from every row.
 *
 * Reading needs billing.view (or billing.manage); every write needs
 * billing.manage, and all of them go through BillingService. Money is
 * pessimistic (house rule 12): buttons disable while the round-trip is in
 * flight, and BillingService's RuntimeExceptions surface inline rather than as
 * a reload.
 *
 * @property-read Collection<int,OrderItem> $lines
 * @property-read array{subtotal:string,taxable:string,tax:string,discount:string,total:string,due:string,currency:string} $totals
 * @property-read Invoice|null $invoice
 * @property-read string $unbilled
 * @property-read string|null $pickedPrice
 * @property-read string|null $stockOnHand
 * @property-read string $lineTotal
 */
#[Lazy]
class Charges extends Component
{
    use AuthorizesRequests, InteractsWithVisit, ShowsTheNextStep;

    /** This section's work is what opens the gate out of Billing. */
    protected function ownsStage(): VisitStage
    {
        return VisitStage::Billing;
    }

    /** 'service' — off the price list. 'product' — off the pharmacy shelf. */
    public string $kind = 'service';

    public ?int $service_id = null;

    public ?int $stock_item_id = null;

    public string $quantity = '1';

    /** Why this charge is on the bill, in the words of whoever put it there. */
    public ?string $note = null;

    /** Bumped on every open, so the picker never keeps a stale pick. */
    public int $formNonce = 0;

    public ?string $discount = '0';

    /**
     * The panel shows the bill; the two writes live behind buttons, in their
     * own dialogs. A panel that renders its forms inline reads as one long
     * form rather than as a record of what this visit has been charged.
     */
    public bool $showAdd = false;

    public bool $showInvoice = false;

    public bool $showDiscount = false;

    /** 'amount' — a number somebody negotiated. 'percent' — a rule. */
    public string $discountType = 'amount';

    public ?string $discountValue = null;

    public ?string $discountReason = null;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;

        $this->authorize('view', $this->visit);
        $this->authorizeRead();
    }

    /** A billing reader may look; only billing.manage may change the bill. */
    private function authorizeRead(): void
    {
        $user = Auth::user();

        abort_unless($user?->can('billing.view') || $user?->can('billing.manage'), 403);
    }

    private function authorizeWrite(): void
    {
        abort_unless(Auth::user()?->can('billing.manage'), 403);
    }

    /** A sibling panel billed something (lab / radiology / dispensing). */
    #[On('visit-updated')]
    public function refreshCharges(): void
    {
        unset($this->visit, $this->lines, $this->totals, $this->invoice, $this->unbilled, $this->nextStep);
    }

    /**
     * Every line, with the work that raised it.
     *
     * The order comes along because each row offers a way into it — the only
     * place a charge can be changed.
     *
     * @return Collection<int, OrderItem>
     */
    #[Computed]
    public function lines(): Collection
    {
        return $this->visit->orderItems()
            ->with('order')
            ->orderBy('order_items.order_id')
            ->orderBy('order_items.id')
            ->get();
    }

    /** The invoice this visit has, if it has been raised. */
    #[Computed]
    public function invoice(): ?Invoice
    {
        return $this->visit->invoices()
            ->where('status', '!=', \App\Enums\InvoiceStatus::Void->value)
            ->first();
    }

    /** @return array{subtotal:string,taxable:string,tax:string,discount:string,total:string,due:string,currency:string} */
    #[Computed]
    public function totals(): array
    {
        return app(BillingService::class)->totalsFor($this->visit);
    }

    /**
     * Work charged to this visit that is on no invoice.
     *
     * An invoice snapshots its lines, and the bill above it keeps adding up
     * the LIVE ones — so the two drift apart the moment anything is charged
     * afterwards. That is not a hypothetical: AdmissionService bills the bed
     * charge when a patient is discharged, which routinely happens after the
     * counter has already invoiced and been paid.
     *
     * The screen used to show both figures, one under the other, with nothing
     * saying they were different things — a bill reading 316,000 directly above
     * an invoice reading 120,000 and the word "Settled". Whatever the right
     * answer is, silence is not it.
     *
     * Zero when there is no invoice, or when nothing has been added since.
     */
    #[Computed]
    public function unbilled(): string
    {
        $invoice = $this->invoice;

        if ($invoice === null) {
            return '0.00';
        }

        $gap = bcsub($this->totals['due'], (string) $invoice->total, 2);

        return bccomp($gap, '0', 2) > 0 ? $gap : '0.00';
    }

    /** How a <livewire:ui.select-search> child hands its pick back. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (in_array($name, ['service_id', 'stock_item_id'], true)) {
            $this->{$name} = $id;
            unset($this->pickedPrice, $this->lineTotal);
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (in_array($name, ['service_id', 'stock_item_id'], true)) {
            $this->{$name} = null;
            unset($this->pickedPrice, $this->lineTotal);
        }
    }

    /** Switching between the price list and the shelf clears the other pick. */
    public function updatedKind(): void
    {
        $this->service_id = null;
        $this->stock_item_id = null;
        $this->formNonce++;
        $this->resetErrorBag();
        unset($this->pickedPrice, $this->lineTotal, $this->stockOnHand);
    }

    /** The catalogue price of whatever is currently picked. */
    #[Computed]
    public function pickedPrice(): ?string
    {
        if ($this->kind === 'product') {
            $item = $this->stock_item_id === null ? null : StockItem::find($this->stock_item_id);

            return $item === null ? null : (string) $item->sale_price;
        }

        $service = $this->service_id === null ? null : Service::find($this->service_id);

        return $service === null ? null : (string) $service->price;
    }

    /** How much is left on the shelf, so nobody sells what is not there. */
    #[Computed]
    public function stockOnHand(): ?string
    {
        if ($this->kind !== 'product' || $this->stock_item_id === null) {
            return null;
        }

        $item = StockItem::find($this->stock_item_id);

        return $item === null
            ? null
            : trim(rtrim(rtrim((string) $item->current_quantity, '0'), '.').' '.$item->unit);
    }

    /** Never typed: price × quantity, in bcmath, like every other line here. */
    #[Computed]
    public function lineTotal(): string
    {
        $price = $this->pickedPrice;
        if ($price === null) {
            return '0.00';
        }

        $qty = \App\Support\HospitalSettings::decimal($this->quantity, 2);

        return bccomp($qty, '0', 2) <= 0 ? '0.00' : bcmul($price, $qty, 2);
    }

    // ── The discount agreed at the counter ───────────────────────────────

    public function openDiscount(): void
    {
        $this->authorizeWrite();

        $visit = $this->visit;
        $this->discountType = ($visit->discount_type ?? DiscountType::Amount)->value;
        $this->discountValue = bccomp((string) $visit->discount_value, '0', 2) > 0
            ? rtrim(rtrim((string) $visit->discount_value, '0'), '.')
            : null;
        $this->discountReason = $visit->discount_reason;
        $this->resetErrorBag();
        $this->showDiscount = true;
    }

    public function saveDiscount(BillingService $billing): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);
        $this->authorizeWrite();

        $this->validate([
            'discountType' => ['required', new \Illuminate\Validation\Rules\Enum(DiscountType::class)],
            'discountValue' => ['nullable', 'numeric', 'min:0'],
            'discountReason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $billing->applyDiscount(
                $visit,
                DiscountType::from($this->discountType),
                (string) ($this->discountValue ?? '0'),
                $this->discountReason,
                Auth::id(),
            );
        } catch (RuntimeException $e) {
            $this->addError('discountValue', $e->getMessage());

            return;
        }

        $this->showDiscount = false;
        $this->refreshCharges();

        $this->dispatch('toast', message: 'Discount saved.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function openAdd(): void
    {
        $this->authorizeWrite();
        $this->reset(['kind', 'service_id', 'stock_item_id', 'quantity', 'note']);
        $this->formNonce++;
        $this->resetErrorBag();
        unset($this->pickedPrice, $this->lineTotal, $this->stockOnHand);
        $this->showAdd = true;
    }

    /** Open the order that owns a line — the only place it can be changed. */
    public function openOrder(int $lineId): void
    {
        $this->authorize('view', $this->visit);

        // Loudly, as everywhere else that takes an id: a row that is not on
        // this bill is a bug or a tampered request, never something to shrug at.
        /** @var OrderItem $line */
        $line = $this->visit->orderItems()->whereKey($lineId)->firstOrFail();

        $this->dispatch('order-open', visitId: $this->visitId, orderId: $line->order_id);
    }

    /**
     * Put the bill back in step with itself.
     *
     * Line totals are stored rather than derived, so a catalogue reprice can
     * never move a charge already raised — and the cost of that is that a line
     * CAN drift with nothing to notice. This notices.
     */
    public function rebill(BillingService $billing): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);
        $this->authorizeWrite();

        try {
            $result = $billing->recompute($visit, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->refreshCharges();

        $this->dispatch('toast', type: $result['fixed'] > 0 ? 'warning' : 'success', message: $result['fixed'] > 0
            ? $result['fixed'].' line(s) were out of step and have been put right.'
            : 'The bill is in step — '.$result['checked'].' line(s) checked.');

        if ($result['fixed'] > 0) {
            $this->dispatch('visit-updated');
        }
    }

    public function openInvoice(): void
    {
        $this->authorize('create', Invoice::class);
        $this->resetErrorBag();
        $this->showInvoice = true;
    }

    public function addLine(BillingService $billing): void
    {
        $visit = $this->visit;
        $this->authorize('view', $visit);
        $this->authorizeWrite();

        $this->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'service_id' => [$this->kind === 'service' ? 'required' : 'nullable', 'integer'],
            'stock_item_id' => [$this->kind === 'product' ? 'required' : 'nullable', 'integer'],
        ], [
            'service_id.required' => 'Choose a service.',
            'stock_item_id.required' => 'Choose a product.',
        ]);

        try {
            $line = $this->kind === 'product'
                ? $this->sellProduct($visit)
                : $billing->orderService($visit, (int) $this->service_id, $this->quantity, Auth::id());
        } catch (Throwable $e) {
            // "Not enough Amoxicillin in stock: 3 available, 10 requested" is
            // the reader's business; anything else is not.
            $this->addError($this->kind === 'product' ? 'stock_item_id' : 'service_id',
                $e instanceof RuntimeException ? $e->getMessage() : 'That could not be added.');

            return;
        }

        if (($this->note ?? '') !== '') {
            $line->update(['notes' => trim((string) $this->note)]);
        }

        $this->reset(['kind', 'service_id', 'stock_item_id', 'quantity', 'note']);
        $this->showAdd = false;
        $this->refreshCharges();

        $this->dispatch('toast', message: 'Added to the bill.', type: 'success');
        $this->dispatch('visit-updated');
    }

    /**
     * Sell something off the shelf straight onto the bill.
     *
     * A walk-in buying paracetamol is a real thing, and it still goes through
     * an order: the charge and the stock movement happen together, so the bill
     * and the shelf cannot disagree.
     */
    private function sellProduct(Visit $visit): OrderItem
    {
        /** @var StockItem $item */
        $item = StockItem::findOrFail($this->stock_item_id);

        $orders = app(\App\Services\OrderService::class);

        return DB::transaction(function () use ($orders, $visit, $item) {
            $order = $orders->place($visit, \App\Enums\OrderType::Pharmacy, $item->name, [
                'status' => \App\Enums\OrderStatus::Completed,
            ], Auth::id());

            return $orders->addProductItem($order, $item, $this->quantity, Auth::id());
        });
    }

    public function generateInvoice(BillingService $billing): mixed
    {
        $visit = $this->visit;
        $this->authorize('create', Invoice::class);

        // The discount is the one agreed on the visit, not one typed into this
        // dialog: it was decided at the counter, by someone who may not be
        // whoever is raising the invoice. A percentage is resolved against the
        // bill as it stands at this moment.
        $this->discount = $billing->discountFor($visit);
        $data = $this->validate(InvoiceGenerateRequest::rulesFor());

        try {
            // "already invoiced", "discount exceeds the amount", ClosedPeriodException
            // — every one of them is a RuntimeException raised by BillingService.
            $invoice = $billing->generateInvoice($visit, InvoiceGenerateRequest::normalise($data['discount'] ?? null), Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('discount', $e->getMessage());

            return null;
        }

        // Stays here. The invoice appears in this section with its number, its
        // balance and its PDF — leaving the visit to look at what was just
        // raised from inside it helps nobody.
        $this->showInvoice = false;
        $this->refreshCharges();

        $this->dispatch('toast', message: "Invoice {$invoice->invoice_no} raised.", type: 'success');
        $this->dispatch('visit-updated');

        return null;
    }

    public function render()
    {
        $this->authorize('view', $this->visit);
        $this->authorizeRead();

        return view('livewire.visits.panels.charges');
    }
}
