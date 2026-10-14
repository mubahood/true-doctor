<?php

namespace App\Livewire\Onboarding\Steps;

use App\Services\PracticeService;
use App\Support\Practice\Practice as PracticeProfile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Setup step (and Settings → Your practice): what kind of practice this is
 * (docs/practice.md).
 *
 * Three questions, then one button. Which kind of practice; which other
 * specialties it also offers; which parts of the system it will use. Saving
 * only records the answers — nothing that exists changes. Then the hospital
 * can set itself up from its template in one go: departments, priced
 * services, rooms, wards with beds, lab tests, studies, opening stock and its
 * chart of accounts, each part only where something is missing.
 *
 * The modules follow the choice until the admin touches one; after that they
 * are the admin's, and changing the sector does not overwrite them.
 *
 * @property-read array<string, \App\Support\Practice\Sector> $sectors
 * @property-read PracticeProfile $saved
 * @property-read bool $dirty
 * @property-read list<string> $addable
 * @property-read array<string, array{label:string,new:int,total:int}> $preview
 * @property-read array<string,int> $counts
 */
class Practice extends Component
{
    use InteractsWithSetup;

    public string $sector = '';

    /** @var list<string> packs added on top of the sector's own */
    public array $packs = [];

    /** @var list<string> */
    public array $modules = [];

    /** The admin has changed a module by hand; stop following the sector. */
    public bool $modulesTouched = false;

    /** @var list<string> the parts of the starter setup to write */
    public array $parts = [];

    public function mount(): void
    {
        $this->authorizeSetup();

        $practice = PracticeProfile::of($this->hospital());

        if ($practice->chosen) {
            $this->sector = $practice->sector->key;
            $this->packs = $practice->extraPacks;
            $this->modules = $practice->modules();
            $this->modulesTouched = $practice->modules !== null;
        }

        $this->parts = array_keys($this->preview);
    }

    /** @return array<string, \App\Support\Practice\Sector> */
    #[Computed]
    public function sectors(): array
    {
        return PracticeProfile::sectors();
    }

    /** The practice as saved — what applying would use. */
    #[Computed]
    public function saved(): PracticeProfile
    {
        return PracticeProfile::of($this->hospital()->fresh());
    }

    /** The form differs from what is saved. */
    #[Computed]
    public function dirty(): bool
    {
        $saved = $this->saved;

        if (! $saved->chosen) {
            return true;
        }

        $sort = function (array $list): array {
            sort($list);

            return $list;
        };

        return $saved->sector->key !== $this->sector
            || $sort($saved->extraPacks) !== $sort($this->packs)
            || $sort($saved->modules()) !== $sort($this->modules);
    }

    /** Packs this sector could add, the commonly added first. @return list<string> */
    #[Computed]
    public function addable(): array
    {
        $sector = PracticeProfile::sector($this->sector);

        return $sector === null ? [] : (new PracticeProfile($sector))->addablePacks();
    }

    /** @return array<string, array{label:string,new:int,total:int}> */
    #[Computed]
    public function preview(): array
    {
        $saved = $this->saved;

        return $saved->chosen ? app(PracticeService::class)->preview($saved) : [];
    }

    /** @return array<string,int> */
    #[Computed]
    public function counts(): array
    {
        $sector = PracticeProfile::sector($this->sector);

        return $sector === null ? [] : (new PracticeProfile($sector, PracticeProfile::validPacks($this->packs)))->template()->counts();
    }

    public function pickSector(string $key): void
    {
        if (PracticeProfile::sector($key) === null) {
            return;
        }

        $this->sector = $key;
        // A pack the new sector already includes is no longer an extra.
        $this->packs = array_values(array_diff($this->packs, PracticeProfile::sector($key)->packs));
        $this->followDefaults();
        unset($this->addable, $this->counts, $this->dirty);
    }

    public function togglePack(string $key): void
    {
        if (PracticeProfile::pack($key) === null) {
            return;
        }

        $this->packs = in_array($key, $this->packs, true)
            ? array_values(array_diff($this->packs, [$key]))
            : [...$this->packs, $key];

        $this->followDefaults();
        unset($this->counts, $this->dirty);
    }

    public function toggleModule(string $key): void
    {
        if (! array_key_exists($key, PracticeProfile::MODULES)) {
            return;
        }

        $this->modules = in_array($key, $this->modules, true)
            ? array_values(array_diff($this->modules, [$key]))
            : PracticeProfile::validModules([...$this->modules, $key]);

        $this->modulesTouched = true;
        unset($this->dirty);
    }

    /** Back to what the sector and its specialties switch on. */
    public function resetModules(): void
    {
        $this->modulesTouched = false;
        $this->followDefaults();
        unset($this->dirty);
    }

    public function save(PracticeService $service): void
    {
        $this->authorizeSetup();

        if (PracticeProfile::sector($this->sector) === null) {
            $this->addError('sector', 'Choose the kind of practice closest to yours.');

            return;
        }

        try {
            $service->choose($this->hospital(), $this->sector, $this->packs, $this->modulesTouched ? $this->modules : null);
        } catch (\DomainException $e) {
            $this->addError('sector', $e->getMessage());

            return;
        }

        unset($this->saved, $this->preview, $this->dirty);
        $this->parts = array_keys(array_filter($this->preview, fn (array $p, string $k) => $p['new'] > 0 || $k === 'accounts', ARRAY_FILTER_USE_BOTH));
        $this->stepCompleted('Saved — '.PracticeProfile::sector($this->sector)->label.'.');
    }

    /** One click: everything the template describes that the hospital does not have yet. */
    public function apply(PracticeService $service): void
    {
        $this->authorizeSetup();

        if ($this->dirty) {
            $this->dispatch('toast', message: 'Save your changes first, so the setup matches them.', type: 'error');

            return;
        }

        $parts = array_values(array_intersect($this->parts, array_keys(PracticeService::PARTS)));
        if ($parts === []) {
            $this->dispatch('toast', message: 'Tick at least one part to set up.', type: 'error');

            return;
        }

        try {
            $created = $service->apply($this->saved, $parts, Auth::id());
        } catch (\DomainException|\App\Exceptions\ClosedPeriodException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->preview);
        $total = array_sum($created);

        $this->stepCompleted($total > 0
            ? $total.' things set up — review the prices on the price list before you start billing.'
            : 'Everything in your template is already set up.');
    }

    private function followDefaults(): void
    {
        $sector = PracticeProfile::sector($this->sector);

        if (! $this->modulesTouched && $sector !== null) {
            $this->modules = PracticeProfile::defaultModules($sector, PracticeProfile::validPacks($this->packs));
        }
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.practice', [
            'packCatalogue' => PracticeProfile::PACKS,
            'moduleCatalogue' => PracticeProfile::MODULES,
            'chosenSector' => PracticeProfile::sector($this->sector),
        ]);
    }
}
