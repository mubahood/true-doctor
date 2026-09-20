<?php

namespace App\Livewire\Patients\Panels;

use App\Http\Requests\PatientInsuranceRequest;
use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\PatientInsurance;
use App\Services\PatientService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * A patient's insurance coverage. Visible to `insurance.view`
 * (InsuranceClaimPolicy@viewAny); adding/removing needs `insurance.manage`,
 * exactly as the classic panel did.
 */
#[Lazy]
class Insurances extends Component
{
    use AuthorizesRequests, InteractsWithPatient;

    public bool $showAdd = false;

    public ?int $insurance_provider_id = null;

    public ?string $member_no = null;

    public string $coverage_percent = '100';

    public ?string $valid_from = null;

    public ?string $valid_to = null;

    public bool $is_active = true;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;

        // Resolves the patient through the tenant scope (404 cross-tenant) …
        $this->authorize('view', $this->patient);
        // … and the panel itself needs insurance visibility.
        $this->authorize('viewAny', InsuranceClaim::class);
    }

    /** @return Collection<int, PatientInsurance> */
    #[Computed]
    public function coverages(): Collection
    {
        return $this->patient->insurances()->with('provider')->get();
    }

    /** Provider options — only queried while the slide-over renders. */
    #[Computed]
    public function providers(): Collection
    {
        return InsuranceProvider::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function openAdd(): void
    {
        $this->authorize('manage', InsuranceClaim::class);

        $this->reset(['insurance_provider_id', 'member_no', 'coverage_percent', 'valid_from', 'valid_to', 'is_active']);
        $this->resetErrorBag();
        $this->showAdd = true;
    }

    public function add(PatientService $service): void
    {
        $this->authorize('manage', InsuranceClaim::class);

        $data = $this->validate(PatientInsuranceRequest::rulesFor());
        $data['is_active'] = $this->is_active;
        $data['valid_from'] = $data['valid_from'] ?: null;
        $data['valid_to'] = $data['valid_to'] ?: null;

        $service->addInsurance($this->patient, $data);

        $this->showAdd = false;
        $this->reset(['insurance_provider_id', 'member_no', 'coverage_percent', 'valid_from', 'valid_to', 'is_active']);
        unset($this->coverages);

        $this->dispatch('toast', message: 'Insurance coverage added.', type: 'success');
        $this->dispatch('patient-updated');
    }

    public function remove(int $insuranceId, PatientService $service): void
    {
        $this->authorize('manage', InsuranceClaim::class);

        $service->removeInsurance($this->patient, $insuranceId);

        unset($this->coverages);

        $this->dispatch('toast', message: 'Coverage removed.', type: 'success');
        $this->dispatch('patient-updated');
    }

    public function render()
    {
        $this->authorize('viewAny', InsuranceClaim::class);

        return view('livewire.patients.panels.insurances');
    }
}
