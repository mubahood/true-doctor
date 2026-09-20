<?php

namespace App\Livewire\Concerns;

use App\Models\Order;
use App\Models\Service;
use App\Models\StockItem;
use App\Services\OrderService;
use Illuminate\Support\Facades\Auth;

/**
 * What a piece of work USED, as lines waiting to be charged.
 *
 * A consultation, a blood test and an X-ray all consume things: a service off
 * the price list, a product off the pharmacy shelf. The question is the same
 * whoever is being asked it, so it is asked the same way — one picker each,
 * whole-unit quantities, a running total, and nothing written until the screen
 * is submitted, so backing out leaves no half-finished order and no charge on
 * the patient's bill.
 *
 * The host says which Order the lines belong to (`chargeTo`) and whether the
 * current user may add them (`assertMayCharge`). Everything else, including
 * the stock a product moves, is OrderService.
 */
trait ChargesWorkDone
{
    /**
     * @var list<array{kind:string,id:int,name:string,unit_price:string,quantity:string,stock:string|null}>
     */
    public array $provided = [];

    /**
     * Bumped whenever a pick changes what the pickers should offer, so a child
     * remounts rather than keeping a selection the parent has dropped. It lives
     * here because the pickers do (docs/visits.md).
     */
    public int $formNonce = 0;

    /** The order the lines are charged to, or null when nothing is open. */
    abstract protected function chargeTo(): ?Order;

    abstract protected function assertMayCharge(): void;

    /** Load the lines already on the order, so a reopened screen shows them. */
    protected function loadProvided(?Order $order): void
    {
        $this->provided = $order === null ? [] : $order->items
            // A line taken off stays on the order as cancelled, for the bill's
            // own history. It is not still being used, so it is not shown.
            ->reject(fn ($item) => $item->status === \App\Enums\OrderItemStatus::Cancelled)
            ->map(fn ($item) => [
                'kind' => $item->stock_item_id !== null ? 'product' : 'service',
                'id' => (int) ($item->stock_item_id ?? $item->service_id),
                'name' => (string) $item->name,
                'unit_price' => (string) $item->unit_price,
                'quantity' => (string) (int) $item->quantity,
                'stock' => null,
            ])->values()->all();
    }

    /**
     * A picker offered a service or a product. True if it was ours to take.
     *
     * The host owns `select-search:picked` — it has its own pickers — so it
     * asks this first rather than the trait competing for the listener.
     */
    protected function providedPicked(string $name, int $id): bool
    {
        if ($name !== 'provided_service_id' && $name !== 'provided_product_id') {
            return false;
        }

        $this->addProvided($name === 'provided_product_id' ? 'product' : 'service', $id);
        $this->formNonce++;

        return true;
    }

    /**
     * Add one. The price is the catalogue's, not the form's.
     *
     * A product also carries what is left on the shelf, so nobody promises
     * more than the pharmacy has — the order refuses it anyway, but finding
     * that out on submit, after the report is written, is the wrong moment.
     */
    private function addProvided(string $kind, int $id): void
    {
        $row = $kind === 'product' ? StockItem::find($id) : Service::find($id);

        if ($row === null) {
            return;
        }

        foreach ($this->provided as $i => $line) {
            if ($line['kind'] === $kind && $line['id'] === $row->id) {
                // Choosing the same thing twice means two of it, not a second
                // line saying the same as the first.
                $this->provided[$i]['quantity'] = (string) ((int) $line['quantity'] + 1);

                return;
            }
        }

        $this->provided[] = $kind === 'product'
            ? [
                'kind' => 'product',
                'id' => $row->id,
                'name' => (string) $row->name,
                'unit_price' => (string) $row->sale_price,
                'quantity' => '1',
                'stock' => (string) (int) $row->current_quantity,
            ]
            : [
                'kind' => 'service',
                'id' => $row->id,
                'name' => (string) $row->name,
                'unit_price' => (string) $row->price,
                'quantity' => '1',
                'stock' => null,
            ];
    }

    public function removeProvided(int $index): void
    {
        unset($this->provided[$index]);
        $this->provided = array_values($this->provided);
    }

    /** What these lines come to. */
    public function providedTotal(): string
    {
        $total = '0';

        foreach ($this->provided as $line) {
            $total = bcadd($total, bcmul((string) $line['unit_price'], (string) $line['quantity'], 4), 4);
        }

        return $total;
    }

    /** @return array<string,list<string>> the rules the host folds into its own */
    protected function providedRules(): array
    {
        return [
            'provided' => ['array'],
            'provided.*.id' => ['required', 'integer'],
            // Whole units. You use two swabs or one tube; a spinner stepping
            // by a hundredth is not an answer anybody meant.
            'provided.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string,string> */
    protected function providedMessages(): array
    {
        return [
            'provided.*.quantity.integer' => 'How many is a whole number.',
            'provided.*.quantity.min' => 'Take the line off instead of asking for none of it.',
        ];
    }

    /** Write the lines onto the order. Returns how many stand. */
    protected function chargeProvided(): int
    {
        $this->assertMayCharge();

        $order = $this->chargeTo();

        if ($order === null) {
            return 0;
        }

        app(OrderService::class)->syncLines($order, array_map(
            fn (array $line) => [
                'kind' => $line['kind'],
                'id' => (int) $line['id'],
                'quantity' => (string) $line['quantity'],
            ],
            $this->provided,
        ), Auth::id());

        return count($this->provided);
    }
}
