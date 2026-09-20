<?php

namespace App\Livewire\Beds;

use App\Enums\BedStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Http\Requests\BedRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Ward;
use App\Support\PlanLimit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Beds catalogue — live table + slide-over create/edit (CrudModal).
 * Validation is the same BedRequest::rulesFor() the rest of the app uses.
 *
 * Occupancy rules carried over from the classic controller: the plan's bed
 * limit is enforced on create, an occupied bed's status can never be flipped
 * by hand, and an occupied bed cannot be deleted.
 *
 * A row says a bed's rate and its state. What it cannot say is who is in it,
 * how long they have been there, or how much of the year the bed has actually
 * earned — and those are what somebody looking down a bed list is deciding on.
 *
 * @property-read Bed|null $peeked
 * @property-read Collection<int,Admission> $peekedStays
 * @property-read array<string,int|string> $peekedFigures
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, PeeksAndEditsRecords, WithTable;

    public ?int $ward_id = null;

    public string $name = '';

    public string $daily_charge = '';

    public ?string $status = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Bed::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Bed::class;
    }

    protected function formFields(): array
    {
        return ['ward_id', 'name', 'daily_charge', 'status', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Bed';
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return BedRequest::rulesFor($this->editingId);
    }

    /**
     * Choosing a ward fills in that ward's standard nightly rate.
     *
     * Only for a NEW bed, and only while the charge is still untouched: a bed
     * that already costs something has been priced deliberately, and moving
     * it between wards must not quietly reprice it.
     */
    public function updatedWardId($value): void
    {
        if ($this->editingId !== null || $value === null) {
            return;
        }

        if ($this->daily_charge !== '' && $this->daily_charge !== '0' && $this->daily_charge !== '0.00') {
            return;
        }

        $ward = Ward::find($value);

        if ($ward !== null) {
            $this->daily_charge = (string) $ward->default_daily_charge;
        }
    }

    /** The subscription plan caps how many beds a hospital may own. */
    protected function assertCreatable(): void
    {
        try {
            app(PlanLimit::class)->assertCanCreate('beds');
        } catch (PlanLimitExceededException $e) {
            throw new \DomainException($e->getMessage(), 0, $e);
        }
    }

    /** Never let the catalogue form flip an occupied bed's status by hand. */
    protected function beforeSave(array &$data, ?Model $model): void
    {
        if ($model instanceof Bed && $model->status === BedStatus::Occupied) {
            unset($data['status']);
        }
    }

    protected function assertDeletable(Model $model): void
    {
        if ($model instanceof Bed && $model->status === BedStatus::Occupied) {
            throw new \DomainException('An occupied bed cannot be deleted.');
        }
    }

    /** Ward options — evaluated only while the modal renders. */
    #[Computed]
    public function wards()
    {
        return Ward::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statuses(): array
    {
        return BedStatus::options();
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return Bed::class;
    }

    protected function peekRelations(): array
    {
        return ['ward', 'currentAdmission.patient'];
    }

    protected function peekCaches(): array
    {
        return ['peekedStays', 'peekedFigures'];
    }

    /**
     * Who has been in it lately.
     *
     * @return Collection<int,Admission>
     */
    #[Computed]
    public function peekedStays(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : Admission::with('patient')
                ->where('bed_id', $this->peekId)
                ->latest('admitted_at')
                ->limit(5)
                ->get();
    }

    /**
     * What the bed has actually done.
     *
     * Nights are what a bed is bought for, and nothing on this screen counted
     * them — so nobody could tell a bed that earns its keep from one that has
     * stood empty since it was bought.
     *
     * @return array<string,int|string>
     */
    #[Computed]
    public function peekedFigures(): array
    {
        $bed = $this->peeked;
        if ($bed === null) {
            return ['stays' => 0, 'nights' => 0, 'earned' => '0.00'];
        }

        $closed = Admission::where('bed_id', $bed->id)->whereNotNull('discharged_at')->get();

        return [
            'stays' => Admission::where('bed_id', $bed->id)->count(),
            'nights' => (int) $closed->sum(fn (Admission $a) => $a->nights()),
            'earned' => $closed->reduce(
                fn (string $sum, Admission $a) => bcadd($sum, (string) $a->bed_charge_total, 2),
                '0.00',
            ),
        ];
    }

    public function render()
    {
        $this->authorize('viewAny', Bed::class);

        $rows = Bed::query()->with('ward')
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->where('name', 'like', "%{$this->search}%")->orWhereHas('ward', fn (Builder $w) => $w->where('name', 'like', "%{$this->search}%"))))
            ->orderBy('ward_id')->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.beds.index', ['rows' => $rows])->title('Beds');
    }
}
