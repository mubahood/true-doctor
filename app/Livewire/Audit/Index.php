<?php

namespace App\Livewire\Audit;

use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\AuditEntry;
use App\Models\Patient;
use App\Models\RecordAccess;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Audit\RecordAccessLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The hospital's audit log (roadmap 07 §3, docs/audit.md): every change to a
 * clinical or money record, field by field, and every time somebody opened or
 * printed a patient's record. The owner's screen, so `manage-settings`.
 *
 * Read-only by design: the trail is append-only, and nothing here can edit or
 * remove an entry.
 *
 * @property-read AuditEntry|null $peeked
 * @property-read User|null $filterUser
 * @property-read Patient|null $filterPatient
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, PeeksRecords, WithTable;

    /** 'changes' · 'opens' */
    #[Url(history: true, except: 'changes')]
    public string $view = 'changes';

    #[Url(history: true, except: '')]
    public string $type = '';

    #[Url(history: true, except: '')]
    public string $event = '';

    #[Url(history: true, except: '')]
    public string $action = '';

    #[Url(history: true, except: '')]
    public string $user = '';

    #[Url(history: true, except: '')]
    public string $patient = '';

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    private const PICKERS = ['user', 'patient'];

    public function mount(): void
    {
        $this->authorize('manage-settings');
        $this->view = $this->view === 'opens' ? 'opens' : 'changes';
    }

    protected function resetsPage(): array
    {
        return ['view', 'type', 'event', 'action', 'user', 'patient', 'from', 'to'];
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->type !== '' || $this->event !== '' || $this->action !== ''
            || $this->user !== '' || $this->patient !== '' || $this->from !== '' || $this->to !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'event', 'action', 'user', 'patient', 'from', 'to']);
        $this->resetPage();
    }

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = (string) $id;
            $this->resetPage();
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = '';
            $this->resetPage();
        }
    }

    #[Computed]
    public function filterUser(): ?User
    {
        return $this->user === '' ? null : User::currentHospital()->find((int) $this->user);
    }

    #[Computed]
    public function filterPatient(): ?Patient
    {
        return $this->patient === '' ? null : Patient::query()->find((int) $this->patient);
    }

    // ── Reading one change ───────────────────────────────────────────────

    protected function peekModel(): string
    {
        return AuditEntry::class;
    }

    protected function peekRelations(): array
    {
        return ['user:id,name,role'];
    }

    protected function authorizePeek(Model $record): void
    {
        $this->authorize('manage-settings');
    }

    public function render()
    {
        $this->authorize('manage-settings');

        $dates = array_filter(['from' => $this->date($this->from), 'to' => $this->date($this->to)]);
        $user = $this->filterUser?->id;

        $rows = $this->view === 'opens'
            ? app(RecordAccessLog::class)->query($dates + [
                'user' => $user,
                'patient' => $this->filterPatient?->id,
                'action' => array_key_exists($this->action, RecordAccess::ACTIONS) ? $this->action : '',
            ])->paginate($this->perPage)
            : app(AuditTrail::class)->query($dates + [
                'user' => $user,
                'type' => array_key_exists($this->type, AuditTrail::types()) ? $this->type : '',
                'event' => array_key_exists($this->event, AuditTrail::EVENTS) ? $this->event : '',
                'search' => $this->search,
            ])->paginate($this->perPage);

        return view('livewire.audit.index', [
            'rows' => $rows,
            'types' => AuditTrail::types(),
            'events' => AuditTrail::EVENTS,
            'actions' => RecordAccess::ACTIONS,
        ])->title('Audit log');
    }

    /** A date filter is a date or nothing. */
    private function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
