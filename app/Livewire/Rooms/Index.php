<?php

namespace App\Livewire\Rooms;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Http\Requests\RoomRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Department;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Rooms catalogue — live table + slide-over create/edit (CrudModal).
 * Validation is the same RoomRequest::rulesFor() the rest of the app uses.
 *
 * A row is five columns; what a room is FOR — its notes, how many it holds,
 * whose department it belongs to — opens over the list.
 *
 * @property-read Room|null $peeked
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?int $department_id = null;

    public ?string $type = null;

    public ?string $status = null;

    public int $capacity = 1;

    public ?string $notes = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Room::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Room::class;
    }

    protected function formFields(): array
    {
        return ['name', 'department_id', 'type', 'status', 'capacity', 'notes'];
    }

    protected function nounLabel(): string
    {
        return 'Room';
    }

    protected function nullableFields(): array
    {
        // type/status are required enums — blanking them would corrupt the column.
        return ['notes'];
    }

    protected function defaults(): array
    {
        return ['capacity' => 1];
    }

    protected function rules(): array
    {
        return RoomRequest::rulesFor($this->editingId);
    }

    /** Department options — evaluated only while the modal renders. */
    #[Computed]
    public function departments()
    {
        return Department::orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function types(): array
    {
        return RoomType::options();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statuses(): array
    {
        return RoomStatus::options();
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return Room::class;
    }

    protected function peekRelations(): array
    {
        return ['department'];
    }

    public function render()
    {
        $this->authorize('viewAny', Room::class);

        $rows = Room::query()->with('department')
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.rooms.index', ['rows' => $rows])->title('Rooms');
    }
}
