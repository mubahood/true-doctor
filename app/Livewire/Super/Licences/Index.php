<?php

namespace App\Livewire\Super\Licences;

use App\Livewire\Concerns\WithTable;
use App\Models\StandaloneLicence;
use App\Models\StandaloneProduct;
use App\Services\StandaloneSalesService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Every standalone licence, online and offline (docs/proposals/standalone-edition.md §7).
 *
 * Online sales issue their own licences; this page is for the rest — a bank
 * transfer, a signed contract, a purchase order — and for the things a buyer
 * asks for by phone: another download link, a new address, more updates.
 * Every licence issued here is emailed to the buyer straight away.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithTable;

    public bool $showIssue = false;

    public ?int $productId = null;

    public string $customerName = '';

    public string $customerEmail = '';

    public string $domain = '';

    public string $reference = '';

    public function openIssue(): void
    {
        $this->reset(['productId', 'customerName', 'customerEmail', 'domain', 'reference']);
        $this->resetErrorBag();
        $this->showIssue = true;
    }

    public function issue(StandaloneSalesService $sales): void
    {
        $this->validate([
            'productId' => ['required', 'exists:standalone_products,id'],
            'customerName' => ['required', 'string', 'min:2', 'max:190'],
            'customerEmail' => ['required', 'email', 'max:190'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'reference' => ['required', 'string', 'min:3', 'max:190'],
        ], [], ['productId' => 'product', 'customerName' => 'buyer name', 'customerEmail' => 'buyer email']);

        try {
            $licence = $sales->issueOffline(StandaloneProduct::findOrFail($this->productId), $this->customerName, $this->customerEmail, $this->domain, $this->reference);
        } catch (\DomainException $e) {
            $this->addError('productId', $e->getMessage());

            return;
        }

        $this->showIssue = false;
        $this->dispatch('toast', message: 'Licence issued and emailed to '.$licence->customer_email.'.', type: 'success');
    }

    public function sendLink(string $id, StandaloneSalesService $sales): void
    {
        $licence = StandaloneLicence::findOrFail($id);
        $sales->sendLink($licence);
        $this->dispatch('toast', message: 'A download link was emailed to '.$licence->customer_email.'.', type: 'success');
    }

    public function changeDomain(string $id, string $domain, StandaloneSalesService $sales): void
    {
        try {
            $licence = $sales->changeDomain(StandaloneLicence::findOrFail($id), $domain, byStaff: true);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->dispatch('toast', message: 'The licence now covers '.$licence->domain().'. The buyer must download licence.json again.', type: 'success');
    }

    public function renew(string $id, StandaloneSalesService $sales): void
    {
        $licence = StandaloneLicence::with('product')->findOrFail($id);
        $months = $licence->product->updates_months ?? 12;
        $sales->extend($licence, $months);
        $this->dispatch('toast', message: "Updates extended by {$months} months. Record any payment for it separately.", type: 'success');
    }

    public function revoke(string $id): void
    {
        StandaloneLicence::findOrFail($id)->update(['status' => 'revoked']);
        $this->dispatch('toast', message: 'Downloads stopped for this licence. The installed system keeps working.', type: 'success');
    }

    public function reinstate(string $id): void
    {
        StandaloneLicence::findOrFail($id)->update(['status' => 'active']);
        $this->dispatch('toast', message: 'Licence active again.', type: 'success');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $term = trim($this->search);

        return view('livewire.super.licences.index', [
            'products' => StandaloneProduct::orderBy('name')->get(),
            'licences' => StandaloneLicence::with('product')
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_email', 'like', "%{$term}%")->orWhere('domains', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%")))
                ->orderByDesc('created_at')->paginate($this->perPage),
        ])->title('Standalone licences');
    }
}
