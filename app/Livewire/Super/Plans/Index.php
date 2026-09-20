<?php

namespace App\Livewire\Super\Plans;

use App\Enums\BillingCycle;
use App\Http\Requests\Super\PlanRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\WithTable;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Subscription plans (super-admin) — live search + the slide-over create/edit
 * (CrudModal), authorized by PlanPolicy.
 *
 * The three caps live inside the plan's `limits` JSON under exactly the keys
 * App\Support\PlanLimit enforces — max_staff, max_patients, max_beds (K4). They
 * are not model attributes, so edit() hydrates them in fillExtra() and save()
 * folds them back into `limits` in beforeSave().
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, WithTable;

    /** The plan limit keys this form owns, in display order. */
    private const LIMIT_KEYS = ['max_staff', 'max_patients', 'max_beds'];

    public string $name = '';

    public ?string $description = null;

    public ?string $slug = null;

    public string $price = '';

    public string $billing_cycle = 'monthly';

    public ?int $max_staff = null;

    public ?int $max_patients = null;

    public ?int $max_beds = null;

    /** Marketing bullet points shown on the subscription page's plan card. @var list<string> */
    public array $features = [];

    public bool $is_active = true;

    public bool $is_featured = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Plan::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Plan::class;
    }

    protected function formFields(): array
    {
        return array_merge(
            ['name', 'description', 'slug', 'price', 'billing_cycle', 'is_active', 'is_featured'],
            self::LIMIT_KEYS,
        );
    }

    protected function nounLabel(): string
    {
        return 'Plan';
    }

    protected function nullableFields(): array
    {
        return ['slug', 'description'];
    }

    protected function defaults(): array
    {
        return ['billing_cycle' => 'monthly', 'is_active' => true, 'is_featured' => false, 'features' => []];
    }

    protected function rules(): array
    {
        return PlanRequest::rulesFor($this->editingId);
    }

    /** The caps live in the `limits` JSON, and features in their own JSON — neither is a plain column the generic fill can see. */
    protected function fillExtra(Model $model): void
    {
        if (! $model instanceof Plan) {
            return;
        }

        foreach (self::LIMIT_KEYS as $key) {
            $value = $model->limit($key);
            $this->{$key} = is_numeric($value) ? (int) $value : null;
        }

        $this->features = array_values(array_filter((array) $model->features, fn (string $f) => $f !== ''));
    }

    protected function beforeSave(array &$data, ?Model $model): void
    {
        $limits = [];
        foreach (self::LIMIT_KEYS as $key) {
            $limits[$key] = ($data[$key] ?? null) ?: null;
            unset($data[$key]);
        }
        $data['limits'] = $limits;
        $data['features'] = array_values(array_filter($this->features, fn ($f) => trim($f) !== ''));
    }

    /** A blank row to type the next feature into. */
    public function addFeature(): void
    {
        $this->features[] = '';
    }

    public function removeFeature(int $index): void
    {
        unset($this->features[$index]);
        $this->features = array_values($this->features);
    }

    #[Computed]
    public function cycles(): array
    {
        return collect(BillingCycle::cases())->mapWithKeys(fn (BillingCycle $c) => [$c->value => $c->label()])->all();
    }

    public function render()
    {
        $this->authorize('viewAny', Plan::class);

        $rows = Plan::withCount('subscriptions')
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('price')
            ->paginate($this->perPage);

        return view('livewire.super.plans.index', ['rows' => $rows])->title('Plans');
    }
}
