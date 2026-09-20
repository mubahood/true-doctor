<?php

namespace App\Livewire\RadiologyStudies;

use App\Http\Requests\RadiologyStudyRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\ImportsSamples;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyStudy;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Radiology study catalogue — live table + slide-over create/edit (CrudModal).
 * Validation is the same RadiologyStudyRequest::rulesFor() the rest of the app uses.
 *
 * @property-read RadiologyStudy|null $peeked
 * @property-read array<string,int|string> $peekedUsage
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, ImportsSamples, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public ?string $modality = null;

    public ?string $body_part = null;

    public string $price = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', RadiologyStudy::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return RadiologyStudy::class;
    }

    protected function formFields(): array
    {
        return ['name', 'modality', 'body_part', 'price', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Study';
    }

    protected function nullableFields(): array
    {
        return ['modality', 'body_part'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return RadiologyStudyRequest::rulesFor($this->editingId);
    }

    // ── Starter data import ────────────────────────────────────
    protected function sampleModelClass(): string
    {
        return RadiologyStudy::class;
    }

    protected function sampleSource(): array
    {
        return SampleCatalogue::radiologyStudies();
    }

    protected function persistSample(array $row): void
    {
        RadiologyStudy::firstOrCreate(
            ['name' => $row['name']],
            [
                'modality' => $row['modality'] ?? null,
                'body_part' => $row['body_part'] ?? null,
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
        return RadiologyStudy::class;
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

        // Counted on the radiology order's own items, not OrderItem: these are ordered
        // through their own order, and pointing at the billing line would have
        // printed a confident zero for every row.
        $lines = RadiologyOrderItem::where('radiology_study_id', $this->peekId)
            ->where('created_at', '>=', now()->subDays(self::USAGE_DAYS))
            ->get(['price']);

        return [
            'times' => $lines->count(),
            'earned' => $lines->reduce(
                fn (string $sum, RadiologyOrderItem $l) => bcadd($sum, (string) $l->price, 2),
                '0.00',
            ),
        ];
    }

    public function render()
    {
        $this->authorize('viewAny', RadiologyStudy::class);

        $rows = RadiologyStudy::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.radiology-studies.index', ['rows' => $rows])->title('Radiology studies');
    }
}
