<?php

namespace App\Livewire\DataRequests;

use App\Enums\DataRequestKind;
use App\Enums\DataRequestStatus;
use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\DataRequest;
use App\Models\Patient;
use App\Services\Compliance\DataRequestService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Data requests: every time a patient asked to see, correct or stop a use of
 * their data, when the answer is due, and what was done
 * (docs/compliance/data-subject-rights.md). Rules in DataRequestService.
 *
 * @property-read DataRequest|null $peeked
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use PeeksRecords, WithTable;

    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: '')]
    public string $kind = '';

    public bool $showForm = false;

    public int $formNonce = 0;

    public ?int $patient_id = null;

    public string $requester_name = '';

    public string $requester_contact = '';

    public string $request_kind = 'access';

    public string $received_on = '';

    public string $details = '';

    public string $outcome = '';

    public function mount(): void
    {
        $this->allowed();
    }

    private function allowed(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        // One clinic's log. With no hospital resolved the tenant scope filters
        // nothing, and a platform account would read every clinic's requests.
        abort_if(app(CurrentHospital::class)->id() === null, 403);
    }

    /** @return list<string> */
    protected function resetsPage(): array
    {
        return ['status', 'kind'];
    }

    // ── Logging one ─────────────────────────────────────────────

    public function create(): void
    {
        $this->allowed();
        $this->reset(['patient_id', 'requester_name', 'requester_contact', 'details']);
        $this->request_kind = DataRequestKind::Access->value;
        $this->received_on = today()->toDateString();
        $this->resetValidation();
        $this->formNonce++;
        $this->showForm = true;
    }

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if ($name !== 'patient_id') {
            return;
        }
        $this->patient_id = $id;
        // The patient usually asks for themselves; a parent or guardian is typed over it.
        if (trim($this->requester_name) === '') {
            $this->requester_name = (string) Patient::query()->find($id)?->full_name;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if ($name === 'patient_id') {
            $this->patient_id = null;
        }
    }

    public function save(DataRequestService $service): void
    {
        $this->allowed();
        $data = $this->validate(collect(DataRequestService::rules())->mapWithKeys(fn ($rules, $key) => [$key === 'kind' ? 'request_kind' : $key => $rules])->all());
        $data['kind'] = $data['request_kind'];

        $service->log($data, Auth::user());

        $this->showForm = false;
        $this->dispatch('toast', type: 'success', message: 'Request logged. The answer is due within '.config('compliance.response_days').' days.');
    }

    // ── Closing one ─────────────────────────────────────────────

    protected function peekModel(): string
    {
        return DataRequest::class;
    }

    protected function peekRelations(): array
    {
        return ['patient', 'recorder:id,name', 'closer:id,name'];
    }

    protected function authorizePeek(Model $record): void
    {
        $this->allowed();
    }

    protected function afterPeek(Model $record): void
    {
        $this->outcome = '';
        $this->resetValidation();
    }

    public function answer(DataRequestService $service): void
    {
        $this->closeAs(DataRequestStatus::Done, $service);
    }

    public function refuse(DataRequestService $service): void
    {
        $this->closeAs(DataRequestStatus::Refused, $service);
    }

    private function closeAs(DataRequestStatus $status, DataRequestService $service): void
    {
        $this->allowed();
        $this->validate(['outcome' => ['required', 'string', 'max:2000']], [], ['outcome' => 'what was done']);
        $request = DataRequest::query()->findOrFail((int) $this->peekId);

        $service->close($request, $status, $this->outcome, Auth::user());

        $this->outcome = '';
        $this->refreshPeek();
        $this->dispatch('toast', type: 'success', message: $status === DataRequestStatus::Done ? 'Marked as answered.' : 'Marked as refused, with the reason.');
    }

    public function render(): View
    {
        $this->allowed();

        $rows = DataRequest::query()->with('patient:id,uuid,first_name,last_name,patient_no')
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->kind !== '', fn (Builder $q) => $q->where('kind', $this->kind))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq
                ->where('requester_name', 'like', "%{$this->search}%")
                ->orWhereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$this->search}%")
                    ->orWhere('last_name', 'like', "%{$this->search}%")->orWhere('patient_no', 'like', "%{$this->search}%"))))
            // Open first, the soonest due at the top: the list is a worklist before it is a log.
            ->orderByRaw('case when status = ? then 0 else 1 end', [DataRequestStatus::Open->value])
            ->orderBy('due_on')->orderByDesc('id')
            ->paginate($this->perPage);

        return view('livewire.data-requests.index', [
            'rows' => $rows,
            'kinds' => DataRequestKind::cases(),
            'statuses' => DataRequestStatus::cases(),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->kind !== '',
        ])->title('Data requests');
    }
}
