<?php

namespace App\Livewire\Patients\Tabs;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The patient's account: invoices, payments and debts. Needs billing.view; the
 * insurance claims block additionally needs the insurance section, the card
 * ledger the cards section.
 */
#[Lazy]
class Billing extends Component
{
    use PatientTab, WithPagination;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->requireSection('money');
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function aging(): array
    {
        return $this->summaryService()->aging($this->patient);
    }

    /** @return array{count:int,amount:string} */
    #[Computed]
    public function claims(): array
    {
        return $this->summaryService()->claimsAwaiting($this->patient);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function claimRows(): array
    {
        return $this->summaryService()->claimRows($this->patient);
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function cardLedger(): array
    {
        return $this->summaryService()->cardLedger($this->patient);
    }

    public function paginationView(): string
    {
        return 'livewire.partials.pagination';
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->requireSection('money');
        $service = $this->summaryService();

        return view('livewire.patients.tabs.billing', [
            'invoices' => $service->invoicesPage($this->patient, 10, 'invoices'),
            'payments' => $service->paymentsPage($this->patient, 10, 'payments'),
            'showClaims' => in_array('insurance', $this->sections, true),
            'showCards' => in_array('cards', $this->sections, true),
        ]);
    }
}
