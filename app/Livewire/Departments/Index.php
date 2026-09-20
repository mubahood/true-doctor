<?php

namespace App\Livewire\Departments;

use App\Http\Requests\DepartmentRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\ImportsSamples;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Department;
use App\Models\Room;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Departments catalogue — live table + slide-over create/edit (CrudModal).
 * Validation is the same DepartmentRequest::rulesFor() the API uses; the
 * reference implementation for every catalogue component.
 *
 * A row counts rooms and staff; the dialog NAMES them, which is what somebody
 * counting them was going to ask next.
 *
 * @property-read Department|null $peeked
 * @property-read Collection<int,Room> $peekedRooms
 * @property-read Collection<int,StaffProfile> $peekedStaff
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, ImportsSamples, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?string $code = null;

    public ?int $head_user_id = null;

    public ?string $description = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Department::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Department::class;
    }

    protected function formFields(): array
    {
        return ['name', 'code', 'head_user_id', 'description', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Department';
    }

    protected function nullableFields(): array
    {
        return ['code', 'description'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return DepartmentRequest::rulesFor($this->editingId);
    }

    protected function beforeSave(array &$data, ?Model $model): void
    {
        $data['code'] = filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : null;
    }

    // `heads` was an uncapped `User::currentHospital()->get()` on every render,
    // drawn into a <select>. It is a picker now (docs/visits.md).

    // ── Starter data import ────────────────────────────────────
    protected function sampleModelClass(): string
    {
        return Department::class;
    }

    protected function sampleSource(): array
    {
        return SampleCatalogue::departments();
    }

    protected function persistSample(array $row): void
    {
        Department::firstOrCreate(
            ['name' => $row['name']],
            ['code' => ($row['code'] ?? '') !== '' ? strtoupper(trim($row['code'])) : null, 'is_active' => true],
        );
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return Department::class;
    }

    protected function peekRelations(): array
    {
        return ['head'];
    }

    protected function peekCaches(): array
    {
        return ['peekedRooms', 'peekedStaff'];
    }

    /** @return Collection<int,Room> */
    #[Computed]
    public function peekedRooms(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : Room::where('department_id', $this->peekId)->orderBy('name')->limit(8)->get();
    }

    /** @return Collection<int,StaffProfile> */
    #[Computed]
    public function peekedStaff(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : StaffProfile::with('user')
                ->where('department_id', $this->peekId)
                ->where('is_active', true)
                ->limit(8)
                ->get();
    }

    public function render()
    {
        $this->authorize('viewAny', Department::class);

        $rows = Department::query()
            ->with('head')->withCount(['rooms', 'staffProfiles'])
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.departments.index', ['rows' => $rows])->title('Departments');
    }
}
