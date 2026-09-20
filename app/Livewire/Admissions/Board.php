<?php

namespace App\Livewire\Admissions;

use App\Enums\AdmissionStatus;
use App\Enums\BedStatus;
use App\Livewire\Concerns\AdmitsPatients;
use App\Livewire\Concerns\MovesPatientsBetweenBeds;
use App\Livewire\Concerns\PeeksRecords;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedTransfer;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The occupancy board: every bed in the hospital, and what is in it.
 *
 * This is the screen a ward round is run from and the screen the admissions
 * desk is looking at when somebody asks "have you got a bed?". It used to be a
 * wall of tiles where an occupied one navigated to the admission workspace —
 * which is the wrong answer to every question asked here. "Who is in bed 4",
 * "how long have they been there", "what has that stay cost so far" and "can I
 * put this patient in that bed" are all one bed's worth of information, and
 * going to another page to read it loses the board.
 *
 * So the tile opens a dialog, and the dialog carries the three things the
 * board cannot: the stay so far, the money accruing on it, and the actions —
 * admit, transfer, discharge, take a bed out of service. The transfer and
 * discharge are the SAME ones the admission workspace runs
 * (MovesPatientsBetweenBeds), so the two screens cannot drift.
 *
 * Refreshed by `wire:poll.30s.visible` so a ward screen left open stays current.
 *
 * @property-read Collection<int,Ward> $wards
 * @property-read Bed|null $peeked
 * @property-read array<string,int|string> $figures
 * @property-read Collection<int,BedTransfer> $peekedTransfers
 * @property-read Admission|null $peekedLastStay
 */
#[Layout('layouts.admin')]
class Board extends Component
{
    use AdmitsPatients, AuthorizesRequests, PeeksRecords;
    use MovesPatientsBetweenBeds {
        openTransfer as private openTransferDialog;
        openDischarge as private openDischargeDialog;
    }

    /** Modal fields a <livewire:ui.select-search> child may set. */
    private const PICKERS = ['patient_id', 'bed_id', 'visit_id', 'admitting_doctor_id', 'to_bed_id'];

    /** '' every ward, or a ward's id. */
    #[Url(history: true, except: '')]
    public string $ward = '';

    /** '' any bed, or a BedStatus value. */
    #[Url(history: true, except: '')]
    public string $status = '';

    /** Bed name or the patient in it — a ward round asks for both. */
    #[Url(history: true, except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Admission::class);
    }

    // ── The board ────────────────────────────────────────────────────────

    /** @return Collection<int,Ward> */
    #[Computed]
    public function wards(): Collection
    {
        $term = trim($this->search);

        return Ward::query()
            ->with([
                // getQuery(): the eager load hands us the relation, and constraining
                // its underlying builder is what narrows the beds that come back.
                'beds' => fn (Relation $q) => $this->applyBedFilters($q->getQuery())->orderBy('name'),
                'beds.currentAdmission.patient',
            ])
            ->where('is_active', true)
            ->when($this->ward !== '', fn (Builder $q) => $q->whereKey((int) $this->ward))
            // A ward whose every bed was filtered out is noise, not a result.
            ->when(
                $this->status !== '' || $term !== '',
                fn (Builder $q) => $q->whereHas('beds', fn (Builder $b) => $this->applyBedFilters($b)),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * The one place a bed is narrowed, shared by the eager load and by the
     * whereHas that decides whether a ward has anything left to show.
     *
     * @template TBuilder of Builder<covariant Model>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function applyBedFilters(Builder $query): Builder
    {
        $term = trim($this->search);

        return $query
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq
                ->where('name', 'like', "%{$term}%")
                ->orWhereHas('currentAdmission.patient', fn (Builder $p) => $p
                    ->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('patient_no', 'like', "%{$term}%"))));
    }

    /**
     * What the whole hospital stands at, not the filtered view.
     *
     * Deliberately unfiltered: "seven beds free" has to mean seven beds free,
     * not seven within whatever somebody typed in the search box a moment ago.
     *
     * @return array<string,int|string>
     */
    #[Computed]
    public function figures(): array
    {
        $beds = Bed::query()->whereHas('ward', fn (Builder $q) => $q->where('is_active', true));

        $total = (clone $beds)->count();
        $occupied = (clone $beds)->where('status', BedStatus::Occupied)->count();
        $free = (clone $beds)->where('status', BedStatus::Available)->count();

        return [
            'total' => $total,
            'occupied' => $occupied,
            'free' => $free,
            'maintenance' => (clone $beds)->where('status', BedStatus::Maintenance)->count(),
            'percent' => $total === 0 ? 0 : (int) round($occupied / $total * 100),
        ];
    }

    /** @return array<string,string> */
    #[Computed]
    public function wardOptions(): array
    {
        return Ward::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)->all();
    }

    // ── One bed, over the board ──────────────────────────────────────────

    protected function peekModel(): string
    {
        return Bed::class;
    }

    protected function peekRelations(): array
    {
        return ['ward', 'currentAdmission.patient', 'currentAdmission.admittingDoctor', 'currentAdmission.visit'];
    }

    protected function peekCaches(): array
    {
        return ['peekedTransfers', 'peekedLastStay'];
    }

    /**
     * BedPolicy has no per-bed read rule, and inventing one would deny
     * everyone but the super admin. Whoever may read the board may read a bed
     * on it — that is the same permission, asked the same way.
     */
    protected function authorizePeek(Model $record): void
    {
        $this->authorize('viewAny', Admission::class);
    }

    /**
     * Where this patient has already been moved.
     *
     * A stay that has been through three beds is a different conversation from
     * one that has not moved, and the board showed neither.
     *
     * @return Collection<int,BedTransfer>
     */
    #[Computed]
    public function peekedTransfers(): Collection
    {
        $admission = $this->peeked?->currentAdmission;

        return $admission === null
            ? new Collection
            : BedTransfer::with(['fromBed', 'toBed'])
                ->where('admission_id', $admission->id)
                ->latest('id')
                ->limit(5)
                ->get();
    }

    /**
     * Who was last in an empty bed, and when they left.
     *
     * The question asked of a free bed is not "is it free" — the tile already
     * said that — it is "has it been turned round yet".
     */
    #[Computed]
    public function peekedLastStay(): ?Admission
    {
        if ($this->peeked === null || $this->peeked->currentAdmission !== null) {
            return null;
        }

        return Admission::with('patient')
            ->where('bed_id', $this->peeked->id)
            ->whereNotNull('discharged_at')
            ->latest('discharged_at')
            ->first();
    }

    /**
     * What the stay in this bed has run up so far.
     *
     * The same arithmetic discharge will do — nights × the rate of the bed
     * they are in NOW. A transfer does not re-price the nights before it, and
     * a figure here that disagreed with the bill would be worse than none.
     */
    public function accruedSoFar(Bed $bed, Admission $admission): string
    {
        return bcmul((string) $bed->daily_charge, (string) $admission->nights(), 2);
    }

    // ── Acting on the bed being read ─────────────────────────────────────

    protected function movingAdmission(): ?Admission
    {
        return $this->peeked?->currentAdmission;
    }

    /**
     * Hide the quick view, but remember which bed it was showing.
     *
     * closePeek() would forget the bed, and the bed is what these two dialogs
     * act on. So the reading dialog goes away — nobody transfers one patient
     * while looking at another — while `peekId` stays put.
     */
    public function openTransfer(): void
    {
        $this->openTransferDialog();
        $this->showPeek = false;
    }

    public function openDischarge(): void
    {
        $this->openDischargeDialog();
        $this->showPeek = false;
    }

    protected function afterMovingPatient(): void
    {
        // The bed the patient just left is not the bed the dialog was showing,
        // so the board is re-read whole rather than the one tile patched.
        unset($this->wards, $this->figures);
        $this->forgetPeeked();
        $this->closePeek();
    }

    /**
     * Take a bed out of service, or put it back.
     *
     * A bed being cleaned, repaired or condemned is the commonest thing a ward
     * clerk needs to record and there was nowhere on this screen to record it;
     * they had to find the bed in the beds list and edit it.
     */
    public function setBedStatus(string $status): void
    {
        $bed = $this->peeked;
        if ($bed === null) {
            return;
        }

        $this->authorize('update', $bed);

        $to = BedStatus::tryFrom($status);
        if ($to === null || $to === BedStatus::Occupied) {
            // Occupied is not something anybody sets by hand — it is what
            // admitting a patient DOES, and setting it here would strand a bed
            // that no admission can ever free.
            $this->dispatch('toast', message: 'A bed is only occupied by admitting somebody to it.', type: 'error');

            return;
        }

        if ($bed->status === BedStatus::Occupied) {
            $this->dispatch('toast', message: 'Discharge or transfer the patient first.', type: 'error');

            return;
        }

        $bed->update(['status' => $to]);

        unset($this->wards, $this->figures);
        $this->forgetPeeked();
        $this->dispatch('toast', message: $bed->name.' is now '.strtolower($to->label()).'.', type: 'success');
    }

    // ── Admitting into the bed being read ────────────────────────────────

    /**
     * Admit into the bed the quick view is showing.
     *
     * The bed is carried into the dialog so nobody has to find it again in a
     * picker, and the quick view closes first — two dialogs open at once is how
     * somebody admits into one bed while looking at another.
     */
    public function openAdmitHere(): void
    {
        $bed = $this->peeked;

        if ($bed === null || ! $bed->status->isAssignable()) {
            $this->dispatch('toast', message: 'That bed is not free.', type: 'error');

            return;
        }

        $bedId = $bed->id;
        $this->closePeek();
        $this->openAdmit($bedId);
    }

    /** A new patient in a bed changes the board and the figures above it. */
    protected function afterAdmitting(Admission $admission): void
    {
        unset($this->wards, $this->figures);
    }

    /** A <livewire:ui.select-search> child reports a pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = null;
        }
    }

    public function render()
    {
        $this->authorize('viewAny', Admission::class);

        return view('livewire.admissions.board', [
            'occupied' => BedStatus::Occupied,
            'statuses' => BedStatus::options(),
            'outcomes' => AdmissionStatus::outcomes(),
        ])->title('Occupancy board');
    }
}
