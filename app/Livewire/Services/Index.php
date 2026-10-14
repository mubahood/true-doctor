<?php

namespace App\Livewire\Services;

use App\Enums\OrderItemStatus;
use App\Http\Requests\ServiceRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\ImportsSamples;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\OrderItem;
use App\Models\Service;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Price list — live table + slide-over create/edit (CrudModal), plus a
 * one-click starter-services importer. Validation is the same
 * ServiceRequest::rulesFor() the classic path used. No navigation.
 *
 * A row says what a service costs. Whether anybody has ordered it this quarter
 * — and what that came to — opens over the list, because that is the question
 * a price review is actually asking of every line.
 *
 * @property-read Service|null $peeked
 * @property-read array<string,int|string> $peekedUsage
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, ImportsSamples, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?string $code = null;

    public ?string $category = null;

    /** The income account it earns into; blank earns by the kind of order. */
    public ?string $ledger_account_id = null;

    public string $price = '';

    public bool $tax_exempt = false;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Service::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Service::class;
    }

    protected function formFields(): array
    {
        return ['name', 'code', 'category', 'ledger_account_id', 'price', 'tax_exempt', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Service';
    }

    protected function nullableFields(): array
    {
        return ['code', 'category', 'ledger_account_id'];
    }

    /** Income accounts a service can earn into — only while the form is open. @return \Illuminate\Support\Collection<int, \App\Models\LedgerAccount> */
    #[Computed]
    public function incomeAccounts()
    {
        app(\App\Services\Finance\ChartOfAccounts::class)->ensure();

        return \App\Models\LedgerAccount::query()->active()->where('type', 'income')
            ->where(fn ($q) => $q->whereNull('subtype')->orWhere('subtype', '!=', 'contra_income'))
            ->ordered()->get();
    }

    /** The specialties already in use, offered as the category is typed. @return list<string> */
    #[Computed]
    public function categories(): array
    {
        return Service::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }

    protected function defaults(): array
    {
        return ['is_active' => true, 'tax_exempt' => false];
    }

    protected function rules(): array
    {
        return ServiceRequest::rulesFor($this->editingId);
    }

    // ── Starter data import ────────────────────────────────────
    protected function sampleModelClass(): string
    {
        return Service::class;
    }

    protected function sampleSource(): array
    {
        return SampleCatalogue::services();
    }

    protected function persistSample(array $row): void
    {
        // Filed under its specialty, earning into the specialty's own income
        // account where the practice has one (docs/practice.md).
        $chart = app(\App\Services\Finance\ChartOfAccounts::class);
        $key = $row['income_key'] ?? null;

        Service::firstOrCreate(
            ['name' => $row['name']],
            [
                'price' => max(0, (float) ($row['price'] ?? 0)),
                'tax_exempt' => (bool) ($row['tax_exempt'] ?? false),
                'is_active' => true,
                'category' => $row['category'] ?? null,
                'ledger_account_id' => filled($key) && $chart->has($key) ? $chart->key($key)->id : null,
            ],
        );
    }

    // ── Reading one over the list ────────────────────────────────────────

    /** How far back "lately" reaches when counting what a catalogue line earns. */
    public const USAGE_DAYS = 90;

    protected function peekModel(): string
    {
        return Service::class;
    }

    protected function peekCaches(): array
    {
        return ['peekedUsage'];
    }

    /**
     * Whether anybody is actually ordering it.
     *
     * A price list says what a thing COSTS and nothing about whether it earns
     * its place on the list. Two numbers — how often in the last quarter, and
     * what that came to — are the difference between pruning a catalogue and
     * guessing at it.
     *
     * @return array<string,int|string>
     */
    #[Computed]
    public function peekedUsage(): array
    {
        if ($this->peekId === null) {
            return ['times' => 0, 'earned' => '0.00'];
        }

        $lines = OrderItem::where('service_id', $this->peekId)
            ->where('created_at', '>=', now()->subDays(self::USAGE_DAYS))
            ->where('status', '!=', OrderItemStatus::Cancelled)
            ->get(['quantity', 'line_total']);

        return [
            'times' => $lines->count(),
            'earned' => $lines->reduce(
                fn (string $sum, OrderItem $l) => bcadd($sum, (string) $l->line_total, 2),
                '0.00',
            ),
        ];
    }

    public function render()
    {
        $this->authorize('viewAny', Service::class);

        $rows = Service::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.services.index', ['rows' => $rows])->title('Price list');
    }
}
