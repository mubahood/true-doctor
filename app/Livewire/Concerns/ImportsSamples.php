<?php

namespace App\Livewire\Concerns;

/**
 * Adds a "starter data" import modal to a catalogue component. The component
 * supplies the model, the sample rows (from SampleCatalogue), and how to
 * persist one row. Rows whose name already exists are pre-filtered, and the
 * import is idempotent (firstOrCreate) so re-running never duplicates.
 *
 * Requires the host component to use AuthorizesRequests.
 */
trait ImportsSamples
{
    public bool $showSamples = false;

    /** @var array<int, array<string, mixed>> */
    public array $samples = [];

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    abstract protected function sampleModelClass(): string;

    /** @return list<array<string, mixed>> */
    abstract protected function sampleSource(): array;

    /** Persist a single selected sample row (idempotent). */
    abstract protected function persistSample(array $row): void;

    public function openSamples(): void
    {
        $this->authorize('create', $this->sampleModelClass());

        $existing = $this->sampleModelClass()::query()
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->all();

        $this->samples = collect($this->sampleSource())
            ->reject(fn ($r) => in_array(mb_strtolower(trim((string) $r['name'])), $existing, true))
            ->map(fn ($r) => array_merge($r, ['selected' => true]))
            ->values()
            ->all();

        $this->resetErrorBag();
        $this->showSamples = true;
    }

    public function importSamples(): void
    {
        $this->authorize('create', $this->sampleModelClass());

        $count = 0;
        foreach ($this->samples as $row) {
            if (empty($row['selected']) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            $this->persistSample($row);
            $count++;
        }

        $this->showSamples = false;
        $this->samples = [];

        $this->dispatch(
            'toast',
            message: $count > 0 ? "{$count} item(s) imported." : 'Nothing selected to import.',
            type: $count > 0 ? 'success' : 'info',
        );
    }

    public function toggleAllSamples(bool $selected): void
    {
        foreach ($this->samples as $i => $row) {
            $this->samples[$i]['selected'] = $selected;
        }
    }
}
