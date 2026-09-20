<?php

namespace App\Livewire\Onboarding\Steps;

use App\Enums\BedStatus;
use App\Http\Requests\BedRequest;
use App\Http\Requests\WardRequest;
use App\Models\Bed;
use App\Models\Ward;
use App\Support\HospitalSettings;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Setup step: wards and beds (recommended, never blocks — only hospitals that
 * admit inpatients need it). A ward with no beds cannot admit anyone, so this
 * step creates both together in one action rather than treating beds as a
 * separate step: adding a ward always asks how many beds and at what daily
 * rate, and the suggested set proposes both from real hospital tiering
 * (general cheapest, ICU/private priciest).
 *
 * Editing/removing an already-added ward goes through WardPolicy
 * (AuthorizesRequests), same as the full Wards page — not the looser
 * `authorizeSetup()` the quick-add actions use below. The edit popup only
 * touches name/description: bed count and rate are per-bed, not per-ward, and
 * stay the full Beds page's job. Deleting a ward with beds is refused with the
 * same message the full page gives — remove the beds first.
 */
class Wards extends Component
{
    use AuthorizesRequests, InteractsWithSetup;

    public string $name = '';

    public ?string $description = null;

    public int $bedsCount = 4;

    public string $dailyCharge = '';

    /** Suggested ward names the admin dismissed — kept out of the preview and the batch add. */
    public array $excluded = [];

    /** Editable per-suggestion overrides, keyed by ward name. */
    public array $starterBeds = [];

    public array $starterRates = [];

    // ── Edit-in-popup for an already-added ward ──────────────────────────
    public bool $showEdit = false;

    #[Locked]
    public ?int $editingId = null;

    public string $editName = '';

    public ?string $editDescription = null;

    public function mount(): void
    {
        $this->authorizeSetup();

        foreach ($this->suggestions() as $row) {
            $this->starterBeds[$row['name']] = $row['beds'];
            $this->starterRates[$row['name']] = (string) $row['daily_charge'];
        }
    }

    public function rules(): array
    {
        return array_intersect_key(WardRequest::rulesFor(), array_flip(['name', 'description'])) + [
            'bedsCount' => ['required', 'integer', 'min:1', 'max:50'],
            'dailyCharge' => BedRequest::rulesFor()['daily_charge'],
        ];
    }

    /** @return \Illuminate\Support\Collection<int, Ward> */
    #[Computed]
    public function existing()
    {
        return Ward::query()->withCount('beds')->orderBy('name')->take(20)->get(['id', 'name', 'description']);
    }

    /** The starter rows not already present or dismissed, so the button never duplicates or re-adds. */
    #[Computed]
    public function suggestions(): array
    {
        $have = Ward::query()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        return collect(SampleCatalogue::wards())
            ->reject(fn (array $row) => in_array(mb_strtolower($row['name']), $have, true))
            ->reject(fn (array $row) => in_array($row['name'], $this->excluded, true))
            ->values()
            ->all();
    }

    /** Drop one suggestion from the preview — it is not added, and won't reappear. */
    public function removeSuggestion(string $name): void
    {
        $this->excluded[] = $name;
        unset($this->starterBeds[$name], $this->starterRates[$name], $this->suggestions);
    }

    public function add(): void
    {
        $this->authorizeSetup();
        $data = $this->validate();

        DB::transaction(function () use ($data): void {
            $ward = Ward::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                // The ward records the rate it is giving its beds. Left at
                // zero, the wards screen would show a hospital that every
                // ward costs nothing, and the "apply to every bed" box there
                // would set them all to zero.
                'default_daily_charge' => (string) $data['dailyCharge'],
                'is_active' => true,
            ]);

            $this->createBeds($ward, (int) $data['bedsCount'], (string) $data['dailyCharge']);
        });

        $this->reset(['name', 'description', 'bedsCount', 'dailyCharge']);
        $this->bedsCount = 4;
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Ward added with its beds.');
    }

    /** One click: create every suggested ward — with its starter beds — the hospital does not have yet. */
    public function addCommon(): void
    {
        $this->authorizeSetup();

        foreach ($this->suggestions() as $row) {
            $beds = $this->starterBeds[$row['name']] ?? $row['beds'];
            $rate = $this->starterRates[$row['name']] ?? $row['daily_charge'];

            if (! is_numeric($beds) || (int) $beds < 1 || ! is_numeric($rate) || (float) $rate < 0) {
                $this->addError('starterRates.'.$row['name'], 'Enter a valid bed count and daily rate.');

                return;
            }

            DB::transaction(function () use ($row, $beds, $rate): void {
                $ward = Ward::firstOrCreate(
                    ['name' => $row['name']],
                    ['is_active' => true, 'default_daily_charge' => (string) $rate],
                );

                if ($ward->wasRecentlyCreated) {
                    $this->createBeds($ward, (int) $beds, (string) $rate);
                }
            });
        }

        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Wards added with their beds.');
    }

    private function createBeds(Ward $ward, int $count, string $dailyCharge): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Bed::create([
                'ward_id' => $ward->id,
                'name' => 'Bed '.$i,
                'daily_charge' => $dailyCharge,
                'status' => BedStatus::Available,
                'is_active' => true,
            ]);
        }
    }

    public function currency(): string
    {
        return app(HospitalSettings::class)->get('currency_symbol') ?: app(HospitalSettings::class)->currencyCode();
    }

    /** Open the edit popup for one already-added ward (name/description only — see class docblock). */
    public function edit(int $id): void
    {
        $ward = Ward::findOrFail($id);
        $this->authorize('update', $ward);

        $this->editingId = $ward->id;
        $this->editName = $ward->name;
        $this->editDescription = $ward->description;
        $this->resetErrorBag();
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $ward = Ward::findOrFail($this->editingId);
        $this->authorize('update', $ward);

        $rules = WardRequest::rulesFor($ward->id);
        $data = $this->validate(['editName' => $rules['name'], 'editDescription' => $rules['description']]);

        $ward->update(['name' => $data['editName'], 'description' => $data['editDescription']]);

        $this->showEdit = false;
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Ward updated.');
    }

    /** A ward that still holds beds may not be removed — mirrors Wards\Index::assertDeletable(). */
    public function delete(int $id): void
    {
        $ward = Ward::findOrFail($id);
        $this->authorize('delete', $ward);

        if ($ward->beds()->exists()) {
            $this->dispatch('toast', message: 'This ward has beds — remove them first, from the full Beds page.', type: 'error');

            return;
        }

        $ward->delete();

        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Ward removed.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.wards', ['currency' => $this->currency()]);
    }
}
