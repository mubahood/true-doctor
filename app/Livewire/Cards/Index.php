<?php

namespace App\Livewire\Cards;

use App\Enums\CardHolderStatus;
use App\Enums\CardStatus;
use App\Livewire\Concerns\WithTable;
use App\Models\CardHolder;
use App\Models\CardRecord;
use App\Models\InsuranceProvider;
use App\Models\PatientCard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every card in the hospital (docs/cards.md).
 *
 * The patient page has always had a Cards panel, and it stays — but it can only
 * be reached by already knowing whose card it is. That is the wrong way round
 * for everything a card desk actually does: find a card by its last four
 * digits, see which of an insurer's members are in debt, work down the list.
 *
 * The panel and this page are the same rows, the same figures and the same
 * service; this one simply does not filter to one patient.
 *
 * @property-read PatientCard|null $peeked
 * @property-read Collection<int,CardHolder> $peekedHolders
 * @property-read Collection<int,CardRecord> $peekedRecords
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, WithTable;

    #[Url(history: true, except: '')]
    public string $status = '';

    /** '' any · 'insured' any insurer · 'own' none · or an insurer's id */
    #[Url(history: true, except: '')]
    public string $insurer = '';

    /** Only cards carrying a debt — the list a settlement run is made from. */
    #[Url(history: true, except: false)]
    public bool $owing = false;

    public function mount(): void
    {
        $this->authorize('viewAny', PatientCard::class);
    }

    protected function resetsPage(): array
    {
        return ['status', 'insurer', 'owing'];
    }

    protected function sortableFields(): array
    {
        return ['balance', 'created_at', 'expiry'];
    }

    /** @return Collection<int,InsuranceProvider> */
    #[Computed]
    public function insurers()
    {
        return InsuranceProvider::orderBy('name')->get(['id', 'name']);
    }

    /** What the filtered list adds up to, which is the point of filtering it. */
    #[Computed]
    public function totals(): array
    {
        $rows = $this->query()->get(['balance']);

        $held = '0.00';
        $owed = '0.00';

        foreach ($rows as $row) {
            bccomp((string) $row->balance, '0', 2) < 0
                ? $owed = bcsub($owed, (string) $row->balance, 2)
                : $held = bcadd($held, (string) $row->balance, 2);
        }

        return ['cards' => $rows->count(), 'held' => $held, 'owed' => $owed];
    }

    /** @return Builder<PatientCard> */
    private function query(): Builder
    {
        return PatientCard::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->owing, fn (Builder $q) => $q->where('balance', '<', 0))
            ->when($this->insurer === 'insured', fn (Builder $q) => $q->whereNotNull('insurance_provider_id'))
            ->when($this->insurer === 'own', fn (Builder $q) => $q->whereNull('insurance_provider_id'))
            ->when(
                $this->insurer !== '' && ! in_array($this->insurer, ['insured', 'own'], true),
                fn (Builder $q) => $q->where('insurance_provider_id', (int) $this->insurer),
            )
            ->when($this->search !== '', function (Builder $query) {
                $term = $this->search;
                $query->where(function (Builder $q) use ($term) {
                    $q->where('member_no', 'like', "%{$term}%")
                        ->orWhereHas('patient', fn (Builder $p) => $p
                            ->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('patient_no', 'like', "%{$term}%"));
                });
            });
    }

    /** @return LengthAwarePaginator<int,PatientCard> */
    private function rows(): LengthAwarePaginator
    {
        /** @var Builder<PatientCard> $sorted */
        $sorted = $this->applySort(
            $this->query()
                ->with(['patient', 'provider'])
                ->withCount(['holders' => fn (Builder $q) => $q->where('status', CardHolderStatus::Active)]),
            fn (Builder $q) => $q->latest('id'),
        );

        return $sorted->paginate($this->perPage);
    }

    // ── Reading one without leaving the list ─────────────────────────────

    public bool $showPeek = false;

    public ?int $peekId = null;

    /**
     * One card, over the list rather than instead of it.
     *
     * The row says what a card IS; the questions asked at a desk are what it
     * has DONE and who else may use it — and both were a page away, which
     * lost the place of whoever was working down the list.
     */
    public function peek(int $id): void
    {
        $card = PatientCard::findOrFail($id);
        $this->authorize('view', $card);

        $this->peekId = $card->id;
        unset($this->peeked, $this->peekedHolders, $this->peekedRecords);
        $this->showPeek = true;
    }

    public function closePeek(): void
    {
        $this->reset(['showPeek', 'peekId']);
    }

    #[Computed]
    public function peeked(): ?PatientCard
    {
        return $this->peekId === null
            ? null
            : PatientCard::with(['patient', 'provider', 'issuer'])->find($this->peekId);
    }

    /**
     * Who else may spend on it.
     *
     * A family card is the whole point of holders, and "who else can use this"
     * is the question a cashier asks before anything else.
     *
     * @return Collection<int,CardHolder>
     */
    #[Computed]
    public function peekedHolders(): Collection
    {
        return $this->peekId === null
            ? collect()
            : CardHolder::with('patient')
                ->where('patient_card_id', $this->peekId)
                ->get();
    }

    /**
     * The last few things charged to it.
     *
     * Capped: the whole history is the card's own page, and a dialog that
     * loads a year of transactions to show five opens slowly.
     *
     * @return Collection<int,CardRecord>
     */
    #[Computed]
    public function peekedRecords(): Collection
    {
        return $this->peekId === null
            ? collect()
            : CardRecord::with(['createdBy', 'patient'])
                ->where('patient_card_id', $this->peekId)
                ->latest('id')
                ->limit(5)
                ->get();
    }

    public function render()
    {
        $this->authorize('viewAny', PatientCard::class);

        return view('livewire.cards.index', [
            'rows' => $this->rows(),
            'statuses' => CardStatus::options(),
        ])->title('Cards');
    }
}
