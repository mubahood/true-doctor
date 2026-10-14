<?php

namespace App\Livewire\Super\Products;

use App\Models\StandaloneProduct;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Index extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public string $price = '';

    /** What another period of updates costs; blank keeps renewals offline. */
    public string $renewalPrice = '';

    public int $updatesMonths = 12;

    public ?int $maxStaff = null;

    public bool $isActive = false;

    public function edit(int $id): void
    {
        $product = StandaloneProduct::findOrFail($id);
        $this->editingId = $id;
        $this->name = $product->name;
        $this->slug = $product->slug;
        $this->description = $product->description ?? '';
        $this->price = (string) (float) $product->price_ugx;
        $this->renewalPrice = $product->renewal_price_ugx !== null ? (string) (float) $product->renewal_price_ugx : '';
        $this->updatesMonths = $product->updates_months;
        $this->maxStaff = $product->limits['max_staff'] ?? null;
        $this->isActive = $product->is_active;
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'slug', 'description', 'price', 'renewalPrice', 'maxStaff', 'isActive']);
        $this->updatesMonths = 12;
    }

    public function save(): void
    {
        // The slug is only an internal handle; nobody should have to invent one.
        if (trim($this->slug) === '') {
            $this->slug = \Illuminate\Support\Str::slug($this->name);
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:190'],
            'slug' => ['required', 'alpha_dash', 'max:190', 'unique:standalone_products,slug,'.$this->editingId],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'renewalPrice' => ['nullable', 'numeric', 'min:1', 'max:999999999999'],
            'updatesMonths' => ['required', 'integer', 'min:1', 'max:60'],
            'maxStaff' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'isActive' => ['boolean'],
        ]);
        StandaloneProduct::updateOrCreate(['id' => $this->editingId], [
            'name' => trim($data['name']), 'slug' => $data['slug'],
            'description' => trim($data['description'] ?? ''),
            'price_ugx' => number_format((float) $data['price'], 2, '.', ''),
            'renewal_price_ugx' => filled($data['renewalPrice'] ?? null) ? number_format((float) $data['renewalPrice'], 2, '.', '') : null,
            'updates_months' => $data['updatesMonths'],
            'limits' => $data['maxStaff'] ? ['max_staff' => (int) $data['maxStaff']] : [],
            'is_active' => $data['isActive'],
        ]);
        $this->resetForm();
        $this->dispatch('toast', message: 'Product saved. Orders already made keep the price and update period they were made at.', type: 'success');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.super.products.index', [
            'products' => StandaloneProduct::orderBy('price_ugx')->get(),
        ])->title('Standalone products');
    }
}
