<?php

namespace App\Livewire\Onboarding\Steps;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Http\Requests\RoomRequest;
use App\Models\Room;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Setup step: consultation rooms (recommended, never blocks). Either add one by
 * hand or take a small starter set — every clinic needs at least a couple of
 * named spaces to book appointments into. New rooms start available, unattached
 * to a department; both are refined later from the full Rooms page.
 *
 * Editing/removing an already-added room goes through RoomPolicy
 * (AuthorizesRequests), same as the full Rooms page — not the looser
 * `authorizeSetup()` the quick-add actions use below.
 */
class Rooms extends Component
{
    use AuthorizesRequests, InteractsWithSetup;

    public string $name = '';

    public string $type = 'consultation';

    public int $capacity = 1;

    /** Suggested names the admin dismissed — kept out of the preview and the batch add. */
    public array $excluded = [];

    // ── Edit-in-popup for an already-added room ──────────────────────────
    public bool $showEdit = false;

    #[Locked]
    public ?int $editingId = null;

    public string $editName = '';

    public string $editType = 'consultation';

    public int $editCapacity = 1;

    public function rules(): array
    {
        return array_intersect_key(RoomRequest::rulesFor(), array_flip(['name', 'type', 'capacity']));
    }

    /** @return array<string,string> */
    public function typeOptions(): array
    {
        return RoomType::options();
    }

    /** @return \Illuminate\Support\Collection<int, Room> */
    #[Computed]
    public function existing()
    {
        return Room::query()->orderBy('name')->take(20)->get(['id', 'name', 'type', 'capacity']);
    }

    /** The starter rows not already present or dismissed, so the button never duplicates or re-adds. */
    #[Computed]
    public function suggestions(): array
    {
        $have = Room::query()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        return collect(SampleCatalogue::rooms())
            ->reject(fn (array $row) => in_array(mb_strtolower($row['name']), $have, true))
            ->reject(fn (array $row) => in_array($row['name'], $this->excluded, true))
            ->values()
            ->all();
    }

    /** Drop one suggestion from the preview — it is not added, and won't reappear. */
    public function removeSuggestion(string $name): void
    {
        $this->excluded[] = $name;
        unset($this->suggestions);
    }

    public function add(): void
    {
        $this->authorizeSetup();
        $data = $this->validate();

        Room::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'status' => RoomStatus::Available,
            'capacity' => $data['capacity'],
        ]);

        $this->reset(['name', 'capacity']);
        $this->type = 'consultation';
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Room added.');
    }

    /** One click: create every suggested room the hospital does not have yet. */
    public function addCommon(): void
    {
        $this->authorizeSetup();

        $created = 0;
        foreach ($this->suggestions() as $row) {
            Room::firstOrCreate(
                ['name' => $row['name']],
                ['type' => $row['type'], 'status' => RoomStatus::Available, 'capacity' => $row['capacity']],
            );
            $created++;
        }

        unset($this->existing, $this->suggestions);
        $this->stepCompleted($created > 0 ? $created.' rooms added.' : 'Every suggested room is already set up.');
    }

    /** Open the edit popup for one already-added room. */
    public function edit(int $id): void
    {
        $room = Room::findOrFail($id);
        $this->authorize('update', $room);

        $this->editingId = $room->id;
        $this->editName = $room->name;
        $this->editType = $room->type->value;
        $this->editCapacity = $room->capacity;
        $this->resetErrorBag();
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $room = Room::findOrFail($this->editingId);
        $this->authorize('update', $room);

        $rules = RoomRequest::rulesFor($room->id);
        $data = $this->validate([
            'editName' => $rules['name'],
            'editType' => $rules['type'],
            'editCapacity' => $rules['capacity'],
        ]);

        $room->update(['name' => $data['editName'], 'type' => $data['editType'], 'capacity' => $data['editCapacity']]);

        $this->showEdit = false;
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Room updated.');
    }

    public function delete(int $id): void
    {
        $room = Room::findOrFail($id);
        $this->authorize('delete', $room);

        $room->delete();

        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Room removed.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.rooms');
    }
}
