<?php

namespace App\Livewire\Super\Hospitals;

use App\Enums\HospitalStatus;
use App\Http\Requests\Super\HospitalRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\WithTable;
use App\Models\Hospital;
use App\Models\Scopes\HospitalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Platform hospitals (super-admin) — live search + the slide-over create/edit
 * (CrudModal), authorized by HospitalPolicy. Hospitals are tenants, not tenant
 * rows, so nothing here is hospital-scoped; the subscription preview *is*
 * scoped and must drop HospitalScope explicitly, or a super-admin who has
 * visited one tenant would see the platform list shrink to it (C18).
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, WithTable;

    public string $name = '';

    public ?string $slug = null;

    public ?string $address = null;

    public string $timezone = 'Africa/Kampala';

    public string $currency = 'UGX';

    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize('viewAny', Hospital::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Hospital::class;
    }

    protected function formFields(): array
    {
        return ['name', 'slug', 'address', 'timezone', 'currency', 'status'];
    }

    protected function nounLabel(): string
    {
        return 'Hospital';
    }

    protected function nullableFields(): array
    {
        return ['slug', 'address'];
    }

    protected function defaults(): array
    {
        return ['timezone' => 'Africa/Kampala', 'currency' => 'UGX', 'status' => 'active'];
    }

    protected function rules(): array
    {
        return HospitalRequest::rulesFor($this->editingId);
    }

    #[Computed]
    public function statuses(): array
    {
        return collect(HospitalStatus::cases())->mapWithKeys(fn (HospitalStatus $c) => [$c->value => $c->label()])->all();
    }

    public function render()
    {
        $this->authorize('viewAny', Hospital::class);

        $rows = Hospital::withCount('users')
            ->with(['subscriptions' => fn ($q) => $q->withoutGlobalScope(HospitalScope::class)->latest('starts_at')->limit(1)])
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->where('name', 'like', "%{$this->search}%")->orWhere('slug', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.super.hospitals.index', ['rows' => $rows])->title('Hospitals');
    }
}
