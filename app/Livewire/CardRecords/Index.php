<?php

namespace App\Livewire\CardRecords;

use App\Enums\CardEntryType;
use App\Livewire\Concerns\PeeksRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\CardRecord;
use App\Models\InsuranceProvider;
use App\Models\PatientCard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every movement on every card in the hospital, newest first (docs/cards.md).
 *
 * The card ledger is append-only and has always existed, but until now the only
 * way to read it was card by card, inside a patient. "What went through the
 * cards today", "what has this insurer's float paid for", "when was that top-up
 * taken" — none of them were answerable.
 *
 * Read-only by construction: a ledger row has no edit and no delete anywhere in
 * the system, and this page adds none.
 *
 * A line opens over the ledger rather than jumping to the card it is on: the
 * question asked of a ledger row is what it WAS, which card it left and what
 * it was spent on — and jumping to the card answers none of those while losing
 * the reader's place in a list they were reconciling.
 *
 * @property-read CardRecord|null $peeked
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, PeeksRecords, WithTable;

    /** '' both · credit · debit */
    #[Url(history: true, except: '')]
    public string $type = '';

    /** '' any · 'insured' any insurer · or an insurer's id */
    #[Url(history: true, except: '')]
    public string $insurer = '';

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', PatientCard::class);
    }

    protected function resetsPage(): array
    {
        return ['type', 'insurer', 'from', 'to'];
    }

    /** @return Collection<int,InsuranceProvider> */
    #[Computed]
    public function insurers(): Collection
    {
        return InsuranceProvider::orderBy('name')->get(['id', 'name']);
    }

    /** What the filtered ledger adds up to — in and out, and the difference. */
    #[Computed]
    public function totals(): array
    {
        $in = '0.00';
        $out = '0.00';

        foreach ($this->query()->get(['type', 'amount']) as $row) {
            $row->type === CardEntryType::Credit
                ? $in = bcadd($in, (string) $row->amount, 2)
                : $out = bcadd($out, (string) $row->amount, 2);
        }

        return ['in' => $in, 'out' => $out, 'net' => bcsub($in, $out, 2)];
    }

    /** @return Builder<CardRecord> */
    private function query(): Builder
    {
        return CardRecord::query()
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->insurer === 'insured', fn (Builder $q) => $q
                ->whereHas('card', fn (Builder $c) => $c->whereNotNull('insurance_provider_id')))
            ->when(
                $this->insurer !== '' && $this->insurer !== 'insured',
                fn (Builder $q) => $q->whereHas('card', fn (Builder $c) => $c
                    ->where('insurance_provider_id', (int) $this->insurer)),
            )
            ->when($this->from !== '', fn (Builder $q) => $q
                ->where('created_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to !== '', fn (Builder $q) => $q
                ->where('created_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->when($this->search !== '', function (Builder $query) {
                $term = $this->search;
                $query->where(function (Builder $q) use ($term) {
                    $q->where('description', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%")
                        ->orWhereHas('patient', fn (Builder $p) => $p
                            ->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('patient_no', 'like', "%{$term}%"));
                });
            });
    }

    /** @return LengthAwarePaginator<int,CardRecord> */
    private function rows(): LengthAwarePaginator
    {
        return $this->query()
            ->with(['patient', 'card.patient', 'card.provider', 'createdBy'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage);
    }

    // ── Reading one line over the ledger ─────────────────────────────────

    protected function peekModel(): string
    {
        return CardRecord::class;
    }

    protected function peekRelations(): array
    {
        return ['card.patient', 'card.provider', 'patient', 'createdBy', 'settlement'];
    }

    /**
     * A ledger line has no policy of its own — it is part of the card. Whoever
     * may read the ledger may read a line in it, asked the same way the page
     * itself is gated.
     */
    protected function authorizePeek(Model $record): void
    {
        $this->authorize('viewAny', PatientCard::class);
    }

    public function render()
    {
        $this->authorize('viewAny', PatientCard::class);

        return view('livewire.card-records.index', [
            'rows' => $this->rows(),
            'types' => ['credit' => 'Money in', 'debit' => 'Money out'],
        ])->title('Card records');
    }
}
