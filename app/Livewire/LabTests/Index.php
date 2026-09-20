<?php

namespace App\Livewire\LabTests;

use App\Http\Requests\LabTestRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\ImportsSamples;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lab test catalogue / price list — live table + slide-over create/edit (CrudModal).
 * Validation is the same LabTestRequest::rulesFor() the rest of the app uses.
 *
 * A row truncates the reference range, which is the one field a bench cannot
 * work from half of. The dialog carries it whole, with what the test has been
 * ordered and earned lately.
 *
 * @property-read LabTest|null $peeked
 * @property-read array<string,int|string> $peekedUsage
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, ImportsSamples, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?string $code = null;

    public ?string $specimen = null;

    public ?string $unit = null;

    public ?string $reference_range = null;

    public string $price = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', LabTest::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return LabTest::class;
    }

    protected function formFields(): array
    {
        return ['name', 'code', 'specimen', 'unit', 'reference_range', 'price', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Lab test';
    }

    protected function nullableFields(): array
    {
        return ['code', 'specimen', 'unit', 'reference_range'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return LabTestRequest::rulesFor($this->editingId);
    }

    // ── Starter data import ────────────────────────────────────
    protected function sampleModelClass(): string
    {
        return LabTest::class;
    }

    protected function sampleSource(): array
    {
        return SampleCatalogue::labTests();
    }

    protected function persistSample(array $row): void
    {
        LabTest::firstOrCreate(
            ['name' => $row['name']],
            [
                'specimen' => $row['specimen'] ?? null,
                'unit' => $row['unit'] ?? null,
                'reference_range' => $row['reference_range'] ?? null,
                'price' => max(0, (float) ($row['price'] ?? 0)),
                'is_active' => true,
            ],
        );
    }

    // ── Reading one over the list ────────────────────────────────────────

    /** How far back "lately" reaches when counting what a catalogue line earns. */
    public const USAGE_DAYS = 90;

    protected function peekModel(): string
    {
        return LabTest::class;
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

        // Counted on the lab order's own items, not OrderItem: these are ordered
        // through their own order, and pointing at the billing line would have
        // printed a confident zero for every row.
        $lines = LabOrderItem::where('lab_test_id', $this->peekId)
            ->where('created_at', '>=', now()->subDays(self::USAGE_DAYS))
            ->get(['price']);

        return [
            'times' => $lines->count(),
            'earned' => $lines->reduce(
                fn (string $sum, LabOrderItem $l) => bcadd($sum, (string) $l->price, 2),
                '0.00',
            ),
        ];
    }

    public function render()
    {
        $this->authorize('viewAny', LabTest::class);

        $rows = LabTest::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.lab-tests.index', ['rows' => $rows])->title('Lab tests');
    }
}
