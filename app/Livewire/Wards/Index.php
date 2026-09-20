<?php

namespace App\Livewire\Wards;

use App\Enums\BedStatus;
use App\Http\Requests\WardRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\ImportsSamples;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Bed;
use App\Models\Ward;
use App\Services\WardService;
use App\Support\HospitalSettings;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Wards catalogue — live table + slide-over create/edit (CrudModal).
 * Validation is the same WardRequest::rulesFor() the rest of the app uses.
 *
 * A row counts a ward's beds. How many of them are FREE is the question the
 * count is standing in for, and it opens over the list along with the beds
 * themselves.
 *
 * @property-read Ward|null $peeked
 * @property-read Collection<int,Bed> $peekedBeds
 * @property-read array<string,int|string> $peekedFigures
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, ImportsSamples, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?string $description = null;

    /** The ward's standard nightly rate. What is BILLED lives on the bed. */
    public string $default_daily_charge = '0.00';

    /**
     * Also set every bed in this ward to that amount.
     *
     * Deliberately not remembered between opens and deliberately off by
     * default: it is the one control on this screen that changes what
     * patients are charged, including patients who are in a bed right now.
     */
    public bool $apply_to_beds = false;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Ward::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Ward::class;
    }

    protected function formFields(): array
    {
        return ['name', 'description', 'default_daily_charge', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Ward';
    }

    protected function nullableFields(): array
    {
        return ['description'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true, 'default_daily_charge' => '0.00', 'apply_to_beds' => false];
    }

    protected function rules(): array
    {
        return WardRequest::rulesFor($this->editingId) + [
            // Form state rather than a column on the ward: whether to reprice
            // the beds is a decision about THIS edit, not a property of the
            // ward, and remembering it would be how somebody reprices a ward
            // by accident the next time they rename it.
            'apply_to_beds' => ['boolean'],
        ];
    }

    /**
     * What ticking the box would do, for the warning beside it.
     *
     * The occupied count is the number that matters. `AdmissionService`
     * reads the bed's rate AT DISCHARGE and multiplies it by the whole stay,
     * so repricing an occupied bed reprices every night already spent in it.
     *
     * @return array{beds:int,occupied:int}
     */
    #[Computed]
    public function repriceImpact(): array
    {
        if ($this->editingId === null) {
            return ['beds' => 0, 'occupied' => 0];
        }

        $ward = Ward::find($this->editingId);

        return $ward === null
            ? ['beds' => 0, 'occupied' => 0]
            : app(WardService::class)->repriceImpact($ward);
    }

    /**
     * Apply the rate to the beds, once the ward itself has been saved.
     *
     * Through the service, which locks the beds and saves them one at a time
     * so each price change lands in that bed's activity log.
     */
    protected function afterSave(Model $model, bool $created): void
    {
        if (! $this->apply_to_beds || ! $model instanceof Ward) {
            return;
        }

        $this->authorize('update', $model);

        $result = app(WardService::class)
            ->applyNightlyChargeToBeds($model, (string) $model->default_daily_charge);

        $this->apply_to_beds = false;

        if ($result['beds'] === 0) {
            $this->dispatch('toast', message: 'That ward has no beds to reprice yet.', type: 'info');

            return;
        }

        // Says what happened, and says the part somebody needs to know: the
        // stays that are open right now will be billed at the new rate for
        // every night, including the ones already spent.
        $message = $result['beds'].' '.Str::plural('bed', $result['beds']).' set to '
            .HospitalSettings::money($model->default_daily_charge).' a night.';

        if ($result['occupied'] > 0) {
            $message .= ' '.$result['occupied'].' '.Str::plural('stay', $result['occupied'])
                .' in progress will be billed at the new rate for every night.';
        }

        $this->dispatch('toast', message: $message, type: $result['occupied'] > 0 ? 'warning' : 'success');
    }

    /** A ward that still holds beds may not be removed (was WardController::destroy). */
    protected function assertDeletable(Model $model): void
    {
        if ($model instanceof Ward && $model->beds()->exists()) {
            throw new \DomainException('This ward has beds — remove them first.');
        }
    }

    // ── Starter data import ────────────────────────────────────
    protected function sampleModelClass(): string
    {
        return Ward::class;
    }

    protected function sampleSource(): array
    {
        return SampleCatalogue::wards();
    }

    protected function persistSample(array $row): void
    {
        Ward::firstOrCreate(
            ['name' => $row['name']],
            // The catalogue carries a starter rate; a ward imported without
            // it would read as costing nothing.
            ['is_active' => true, 'default_daily_charge' => (string) ($row['daily_charge'] ?? 0)],
        );
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return Ward::class;
    }

    protected function peekCaches(): array
    {
        return ['peekedBeds', 'peekedFigures'];
    }

    /** @return Collection<int,Bed> */
    #[Computed]
    public function peekedBeds(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : Bed::with('currentAdmission.patient')->where('ward_id', $this->peekId)->orderBy('name')->get();
    }

    /**
     * How full it is, and what a full night of it is worth.
     *
     * @return array<string,int|string>
     */
    #[Computed]
    public function peekedFigures(): array
    {
        $beds = $this->peekedBeds;
        $occupied = $beds->where('status', BedStatus::Occupied)->count();

        return [
            'beds' => $beds->count(),
            'occupied' => $occupied,
            'free' => $beds->where('status', BedStatus::Available)->count(),
            'percent' => $beds->count() === 0 ? 0 : (int) round($occupied / $beds->count() * 100),
            'aNight' => $beds->reduce(
                fn (string $sum, Bed $b) => bcadd($sum, (string) $b->daily_charge, 2),
                '0.00',
            ),
        ];
    }

    public function render()
    {
        $this->authorize('viewAny', Ward::class);

        $rows = Ward::query()->withCount('beds')
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.wards.index', ['rows' => $rows])->title('Wards');
    }
}
