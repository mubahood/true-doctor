<?php

namespace App\Livewire\InsuranceProviders;

use App\Http\Requests\InsuranceProviderRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\InsuranceProvider;
use App\Models\InsuranceTransaction;
use App\Models\PatientCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Insurance providers — live table + slide-over create/edit (CrudModal).
 * Validation is the shared InsuranceProviderRequest::rulesFor(). No navigation.
 *
 * @property-read InsuranceProvider|null $peeked
 * @property-read array<string,int|string> $peekedFigures
 * @property-read Collection<int,InsuranceTransaction> $peekedLedger
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?string $code = null;

    public ?string $contact_person = null;

    public ?string $contact_phone = null;

    public ?string $contact_email = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', InsuranceProvider::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return InsuranceProvider::class;
    }

    protected function formFields(): array
    {
        return ['name', 'code', 'contact_person', 'contact_phone', 'contact_email', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Provider';
    }

    protected function nullableFields(): array
    {
        return ['code', 'contact_person', 'contact_phone', 'contact_email'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return InsuranceProviderRequest::rulesFor($this->editingId);
    }

    // ── Reading one without leaving the list ─────────────────────────────

    protected function peekModel(): string
    {
        return InsuranceProvider::class;
    }

    protected function peekCaches(): array
    {
        return ['peekedFigures', 'peekedLedger'];
    }

    /**
     * `viewAny`, not `view`: the policy has no per-provider read rule — whoever
     * may see the directory may read an entry in it, and inventing an ability
     * here would deny everyone but the super admin.
     */
    protected function authorizePeek(Model $record): void
    {
        $this->authorize('viewAny', InsuranceProvider::class);
    }

    /**
     * What the insurer's cards add up to.
     *
     * Counted here rather than in the view so the dialog makes three queries,
     * not one per card.
     *
     * @return array<string,int|string>
     */
    #[Computed]
    public function peekedFigures(): array
    {
        if ($this->peeked === null) {
            return ['cards' => 0, 'inDebt' => 0, 'owed' => '0.00', 'shortfall' => '0.00'];
        }

        $cards = PatientCard::where('insurance_provider_id', $this->peeked->id);
        $owed = $this->peeked->outstanding();

        return [
            'cards' => (clone $cards)->count(),
            'inDebt' => (clone $cards)->where('balance', '<', 0)->count(),
            'owed' => $owed,
            // Negative means the float cannot clear what the members owe —
            // the one figure that decides whether a settlement run can go
            // ahead today, and it was nowhere on the screen.
            'shortfall' => bcsub((string) $this->peeked->float_balance, $owed, 2),
        ];
    }

    /** @return Collection<int,InsuranceTransaction> */
    #[Computed]
    public function peekedLedger(): Collection
    {
        return $this->peekId === null
            ? collect()
            : InsuranceTransaction::with('createdBy')
                ->where('insurance_provider_id', $this->peekId)
                ->latest('id')
                ->limit(5)
                ->get();
    }

    public function render()
    {
        $this->authorize('viewAny', InsuranceProvider::class);

        $rows = InsuranceProvider::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.insurance-providers.index', ['rows' => $rows])->title('Insurance providers');
    }
}
