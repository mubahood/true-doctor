<?php

namespace App\Livewire\Super\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Http\Requests\Super\RecordSubscriptionPaymentRequest;
use App\Http\Requests\Super\SubscriptionRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\WithTable;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Scopes\HospitalScope;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Platform subscriptions (super-admin) — live search, the slide-over
 * create/edit (CrudModal) and the "Record payment" slide-over that replaces the
 * deleted classic edit page (N1). Authorized by SubscriptionPolicy.
 *
 * Subscription is tenant-scoped, so every read here drops HospitalScope
 * explicitly (C18): a super-admin who has looked at one hospital must still see
 * the whole platform. CrudModal resolves rows with `modelClass()::findOrFail()`
 * and has no query hook, so edit()/save()/delete() are overridden here — the
 * *only* difference is that they resolve through platformQuery().
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, WithTable;

    public ?int $hospital_id = null;

    public ?int $plan_id = null;

    public string $status = 'trialing';

    public ?string $starts_at = null;

    public ?string $ends_at = null;

    public ?string $trial_ends_at = null;

    // ── Record payment slide-over ──────────────────────────────
    public bool $showPayment = false;

    #[Locked]
    public ?int $payingId = null;

    public string $amount = '';

    public string $method = 'manual';

    public ?string $reference = null;

    public ?string $notes = null;

    public ?string $paid_at = null;

    public int $extend_days = 30;

    public function mount(): void
    {
        $this->authorize('viewAny', Subscription::class);
    }

    /**
     * Every subscription read on the platform surface ignores the tenant scope.
     *
     * @return Builder<Subscription>
     */
    private function platformQuery(): Builder
    {
        return Subscription::query()->withoutGlobalScope(HospitalScope::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Subscription::class;
    }

    protected function formFields(): array
    {
        return ['hospital_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'trial_ends_at'];
    }

    protected function nounLabel(): string
    {
        return 'Subscription';
    }

    protected function nullableFields(): array
    {
        return ['ends_at', 'trial_ends_at'];
    }

    protected function defaults(): array
    {
        return ['status' => 'trialing'];
    }

    protected function rules(): array
    {
        return SubscriptionRequest::rulesFor($this->editingId);
    }

    // ── CrudModal overrides: resolve outside the tenant scope ──
    public function edit(int $id): void
    {
        $model = $this->platformQuery()->findOrFail($id);
        $this->authorize('update', $model);

        $this->resetErrorBag();
        $this->editingId = $model->getKey();
        $this->hospital_id = $model->hospital_id;
        $this->plan_id = $model->plan_id;
        $this->status = $model->status->value;
        $this->starts_at = $model->starts_at?->toDateString();
        $this->ends_at = $model->ends_at?->toDateString();
        $this->trial_ends_at = $model->trial_ends_at?->toDateString();

        $this->showForm = true;
    }

    public function save(): void
    {
        $model = $this->editingId ? $this->platformQuery()->findOrFail($this->editingId) : null;

        $model
            ? $this->authorize('update', $model)
            : $this->authorize('create', Subscription::class);

        $data = $this->validate();

        foreach ($this->nullableFields() as $field) {
            if (is_string($data[$field] ?? null) && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }

        if ($model) {
            $model->update($data);
            $created = false;
        } else {
            $model = Subscription::create($data);
            $created = true;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('toast', message: $this->nounLabel().($created ? ' created.' : ' updated.'), type: 'success');
    }

    public function delete(int $id): void
    {
        $model = $this->platformQuery()->findOrFail($id);
        $this->authorize('delete', $model);

        $model->delete();

        $this->dispatch('toast', message: $this->nounLabel().' archived.', type: 'success');
    }

    // ── Record payment ─────────────────────────────────────────
    public function recordPayment(int $id): void
    {
        $subscription = $this->platformQuery()->findOrFail($id);
        $this->authorize('update', $subscription);

        $this->reset(['amount', 'reference', 'notes']);
        $this->method = 'manual';
        $this->extend_days = 30;
        $this->paid_at = now()->toDateString();
        $this->payingId = $subscription->getKey();
        $this->resetErrorBag();
        $this->showPayment = true;
    }

    public function savePayment(SubscriptionService $subscriptions): void
    {
        $subscription = $this->platformQuery()->findOrFail($this->payingId);
        $this->authorize('update', $subscription);

        /** @var \App\Models\User $actor */
        $actor = Auth::user();

        $data = $this->validate(RecordSubscriptionPaymentRequest::rulesFor());

        $subscriptions->recordPayment($subscription, $data, $actor);

        $this->showPayment = false;
        $this->payingId = null;
        $this->reset(['amount', 'reference', 'notes']);
        $this->dispatch('toast', message: 'Payment recorded.', type: 'success');
    }

    // ── Option lists (open modal only) ─────────────────────────
    #[Computed]
    public function hospitals()
    {
        return Hospital::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function plans()
    {
        return Plan::where('is_active', true)->orderBy('price')->get(['id', 'name']);
    }

    #[Computed]
    public function statuses(): array
    {
        return collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $c) => [$c->value => $c->label()])->all();
    }

    public function render()
    {
        $this->authorize('viewAny', Subscription::class);

        $rows = $this->platformQuery()->with(['hospital', 'plan'])
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('hospital', fn (Builder $hh) => $hh->where('name', 'like', "%{$this->search}%")))
            ->latest('starts_at')
            ->paginate($this->perPage);

        return view('livewire.super.subscriptions.index', ['rows' => $rows])->title('Subscriptions');
    }
}
