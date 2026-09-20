<?php

namespace App\Livewire\Admissions\Panels;

use App\Http\Requests\NursingNoteRequest;
use App\Models\NursingNote;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Nursing notes for one admission — an append-only log with an inline add form.
 * Replaces NursingController@storeNote and the classic POST form on
 * admin/admissions/show.blade.php; validation comes from
 * NursingNoteRequest::rulesFor().
 *
 * @property-read Collection<int,NursingNote> $notes
 */
#[Lazy]
class NursingNotes extends Component
{
    use AuthorizesRequests, InteractsWithAdmission;

    public ?string $note = null;

    public function mount(int $admissionId): void
    {
        $this->admissionId = $admissionId;

        $this->authorize('view', $this->admission);
    }

    /** @return Collection<int,NursingNote> */
    #[Computed]
    public function notes(): Collection
    {
        return $this->admission->nursingNotes()->with('recordedBy')->get();
    }

    public function add(): void
    {
        $admission = $this->guardActive();

        $data = $this->validate(NursingNoteRequest::rulesFor());

        $admission->nursingNotes()->create($data + [
            'recorded_by' => Auth::id(), 'created_at' => Carbon::now(),
        ]);

        $this->reset(['note']);
        unset($this->notes);
        $this->dispatch('toast', message: 'Nursing note added.', type: 'success');
    }

    public function render()
    {
        $this->authorize('view', $this->admission);

        return view('livewire.admissions.panels.nursing-notes');
    }
}
