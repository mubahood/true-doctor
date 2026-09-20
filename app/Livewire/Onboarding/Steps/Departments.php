<?php

namespace App\Livewire\Onboarding\Steps;

use App\Http\Requests\DepartmentRequest;
use App\Models\Department;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Setup step: departments. Either add one by hand or take the common set in a
 * single click — most hospitals recognise Outpatient, Maternity and the rest,
 * and typing them all during signup is friction for no benefit.
 *
 * Editing/removing an already-added department goes through DepartmentPolicy
 * (AuthorizesRequests), same as the full Departments page — not the looser
 * `authorizeSetup()` the quick-add actions use below.
 */
class Departments extends Component
{
    use AuthorizesRequests, InteractsWithSetup;

    public string $name = '';

    public ?string $code = null;

    /** Suggested names the admin dismissed — kept out of the preview and the batch add. */
    public array $excluded = [];

    // ── Edit-in-popup for an already-added department ───────────────────
    public bool $showEdit = false;

    #[Locked]
    public ?int $editingId = null;

    public string $editName = '';

    public ?string $editCode = null;

    public function rules(): array
    {
        return array_intersect_key(DepartmentRequest::rulesFor(), array_flip(['name', 'code']));
    }

    /** @return \Illuminate\Support\Collection<int, Department> */
    #[Computed]
    public function existing()
    {
        return Department::query()->where('is_active', true)->orderBy('name')->take(20)->get(['id', 'name', 'code']);
    }

    /** The starter rows not already present or dismissed, so the button never duplicates or re-adds. */
    #[Computed]
    public function suggestions(): array
    {
        $have = Department::query()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        return collect(SampleCatalogue::departments())
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

        Department::create([
            'name' => $data['name'],
            'code' => filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : null,
            'is_active' => true,
        ]);

        $this->reset(['name', 'code']);
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Department added.');
    }

    /** One click: create every common department the hospital does not have yet. */
    public function addCommon(): void
    {
        $this->authorizeSetup();

        $created = 0;
        foreach ($this->suggestions() as $row) {
            Department::firstOrCreate(
                ['name' => $row['name']],
                ['code' => filled($row['code'] ?? null) ? strtoupper((string) $row['code']) : null, 'is_active' => true],
            );
            $created++;
        }

        unset($this->existing, $this->suggestions);
        $this->stepCompleted($created > 0 ? $created.' departments added.' : 'Every common department is already set up.');
    }

    /** Open the edit popup for one already-added department. */
    public function edit(int $id): void
    {
        $department = Department::findOrFail($id);
        $this->authorize('update', $department);

        $this->editingId = $department->id;
        $this->editName = $department->name;
        $this->editCode = $department->code;
        $this->resetErrorBag();
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $department = Department::findOrFail($this->editingId);
        $this->authorize('update', $department);

        $rules = DepartmentRequest::rulesFor($department->id);
        $data = $this->validate(['editName' => $rules['name'], 'editCode' => $rules['code']]);

        $department->update([
            'name' => $data['editName'],
            'code' => filled($data['editCode']) ? strtoupper(trim($data['editCode'])) : null,
        ]);

        $this->showEdit = false;
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Department updated.');
    }

    public function delete(int $id): void
    {
        $department = Department::findOrFail($id);
        $this->authorize('delete', $department);

        $department->delete();

        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Department removed.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.departments');
    }
}
