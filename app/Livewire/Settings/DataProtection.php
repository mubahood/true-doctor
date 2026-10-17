<?php

namespace App\Livewire\Settings;

use App\Enums\DataRequestStatus;
use App\Models\DataExport;
use App\Models\DataRequest;
use App\Models\Hospital;
use App\Services\Compliance\HospitalExport;
use App\Services\Compliance\ProcessingAgreement;
use App\Support\Compliance\ComplianceDocuments;
use App\Support\CurrentHospital;
use App\Support\Edition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → Data protection: the documents the Data Protection and Privacy
 * Act asks a clinic to have, the processing agreement on the online service,
 * and a full export of the hospital's own data (docs/compliance/README.md).
 */
#[Layout('layouts.admin')]
class DataProtection extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
    }

    private function hospital(): ?Hospital
    {
        $id = app(CurrentHospital::class)->id();

        return $id !== null ? Hospital::query()->find($id) : null;
    }

    public function acceptAgreement(ProcessingAgreement $agreement): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        $hospital = $this->hospital() ?? abort(404);
        $agreement->accept($hospital, Auth::user());

        $this->dispatch('toast', type: 'success', message: 'Agreement accepted. The date and your name are recorded.');
    }

    public function requestExport(HospitalExport $exports): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);

        try {
            $exports->request(Auth::user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'warning', message: (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Your export is being prepared. It appears below when it is ready.');
    }

    public function render(ProcessingAgreement $agreement, HospitalExport $exports): View
    {
        $hospital = $this->hospital();
        $list = $hospital === null ? collect() : DataExport::query()->with('requester:id,name')->latest('id')->limit(10)->get();

        return view('livewire.settings.data-protection', [
            'hospital' => $hospital,
            'documents' => ComplianceDocuments::available(),
            'saas' => Edition::isSaas(),
            'agreement' => $hospital !== null ? $agreement->latest($hospital) : null,
            'agreementCurrent' => $hospital !== null && $agreement->isCurrent($hospital),
            'exports' => $list,
            // A link opens only for the hospital's own administrators; nobody else is offered one.
            'links' => $list->filter(fn (DataExport $e) => $e->isDownloadable() && Auth::user()?->hospital_id !== null)->mapWithKeys(fn (DataExport $e) => [$e->id => $exports->link($e)]),
            'pending' => $list->contains(fn (DataExport $e) => $e->status->pending()),
            'openRequests' => $hospital === null ? 0 : DataRequest::query()->where('status', DataRequestStatus::Open)->count(),
        ])->title('Data protection');
    }
}
