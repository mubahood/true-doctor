<?php

namespace App\Livewire\PatientLinks;

use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Services\PatientLinks\PatientLinkService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every link sent to a patient (roadmap 05 §4): what it opens, until when,
 * whether it still works, and who opened it from where (the quick view).
 * A link can be withdrawn here; it stops working at once.
 *
 * @property-read PatientLink|null $peeked
 * @property-read Collection<int,PatientLinkOpen> $peekedOpens
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, PeeksRecords, WithTable;

    #[Url(history: true, except: '')]
    public string $state = '';

    public function mount(): void
    {
        $this->authorize('viewAny', PatientLink::class);
    }

    protected function resetsPage(): array
    {
        return ['state'];
    }

    protected function peekModel(): string
    {
        return PatientLink::class;
    }

    protected function peekRelations(): array
    {
        return ['patient', 'creator', 'revoker', 'message'];
    }

    protected function peekCaches(): array
    {
        return ['peekedOpens'];
    }

    /** @return Collection<int,PatientLinkOpen> the last 20 attempts, newest first */
    #[Computed]
    public function peekedOpens(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : PatientLinkOpen::query()->where('patient_link_id', $this->peekId)->latest('id')->limit(20)->get();
    }

    public function revoke(int $id): void
    {
        $link = PatientLink::query()->findOrFail($id);
        $this->authorize('revoke', $link);

        app(PatientLinkService::class)->revoke($link, auth()->user());
        $this->refreshPeek();
        $this->dispatch('toast', type: 'success', message: 'Link withdrawn. It no longer opens.');
    }

    public function render()
    {
        $this->authorize('viewAny', PatientLink::class);

        $links = PatientLink::query()->with(['patient:id,uuid,first_name,last_name,patient_no', 'creator:id,name'])
            ->when(in_array($this->state, PatientLink::STATES, true), fn ($q) => $q->inState($this->state))
            ->matching($this->search)
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.patient-links.index', [
            'links' => $links,
            'states' => array_combine(PatientLink::STATES, array_map(fn ($s) => PatientLink::stateLabel($s), PatientLink::STATES)),
        ])->title('Patient links');
    }
}
