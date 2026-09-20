<?php

namespace App\Livewire\Patients\Panels;

use App\Http\Requests\PatientDocumentRequest;
use App\Models\PatientDocument;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Patient documents. Files are written to (and removed from) the private disk
 * by DocumentService; downloads stay classic links to the streaming controller
 * so the browser can save the file (house rule 1's download exemption).
 */
#[Lazy]
class Documents extends Component
{
    use AuthorizesRequests, InteractsWithPatient, WithFileUploads;

    public bool $showUpload = false;

    public string $type = 'other';

    public ?string $note = null;

    public $file = null;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;

        $this->authorize('view', $this->patient);
    }

    /** @return Collection<int, PatientDocument> */
    #[Computed]
    public function documents(): Collection
    {
        return $this->patient->documents()->with('uploader')->get();
    }

    /** @return list<string> */
    #[Computed]
    public function types(): array
    {
        return PatientDocument::TYPES;
    }

    public function openUpload(): void
    {
        $this->authorize('manageDocuments', $this->patient);

        $this->reset(['type', 'note', 'file']);
        $this->resetErrorBag();
        $this->showUpload = true;
    }

    public function upload(DocumentService $service): void
    {
        $this->authorize('manageDocuments', $this->patient);

        $data = $this->validate(PatientDocumentRequest::rulesFor());

        $service->store(
            $this->patient,
            $this->file,
            $data['type'],
            $data['note'] ?: null,
            Auth::id(),
        );

        $this->showUpload = false;
        $this->reset(['type', 'note', 'file']);
        unset($this->documents);

        $this->dispatch('toast', message: 'Document uploaded.', type: 'success');
        $this->dispatch('patient-updated');
    }

    public function delete(int $documentId, DocumentService $service): void
    {
        $this->authorize('manageDocuments', $this->patient);

        /** @var PatientDocument $document */
        $document = $this->patient->documents()->whereKey($documentId)->firstOrFail();
        $service->delete($document);

        unset($this->documents);

        $this->dispatch('toast', message: 'Document removed.', type: 'success');
        $this->dispatch('patient-updated');
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        return view('livewire.patients.panels.documents');
    }
}
