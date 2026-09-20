<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;

/**
 * Reading one record over the list it is in.
 *
 * A row shows what fits. The record has more, and the useful part is usually
 * the part that did not fit — how an admission got to its eleventh night, what
 * an invoice is actually made of, which of a doctor's days are already full.
 * Opening a page for that loses the place of whoever was working down the list,
 * and they were only asking one question.
 *
 * Every list in the back office therefore answers that question in a dialog,
 * and this holds the four moving parts once so twenty screens cannot drift:
 * the open, the close, the record, and the authorisation that decides whether
 * this reader may see it at all.
 *
 * A component supplies the model and (optionally) what to eager-load; anything
 * else it wants cached beside the record it names in peekCaches() so opening a
 * different record forgets the old one's figures.
 *
 * @see \App\Livewire\Concerns\PeeksStockItems for the one that predates this
 *      and carries a ledger of its own.
 */
trait PeeksRecords
{
    public bool $showPeek = false;

    public ?int $peekId = null;

    /** @return class-string<Model> */
    abstract protected function peekModel(): string;

    /**
     * What the dialog needs loaded with the record.
     *
     * Named here rather than in the view because a dialog that lazy-loads five
     * relations makes five queries every time it opens.
     *
     * @return list<string>
     */
    protected function peekRelations(): array
    {
        return [];
    }

    /**
     * Other computed properties that describe the record being read.
     *
     * @return list<string>
     */
    protected function peekCaches(): array
    {
        return [];
    }

    /**
     * May this reader see this record?
     *
     * `view` by default. A component whose policy has no per-record read rule
     * overrides this — inventing an ability that does not exist denies
     * everyone but the super admin, silently.
     */
    protected function authorizePeek(Model $record): void
    {
        $this->authorize('view', $record);
    }

    /** Somewhere for a component to react — refresh a figure, log a read. */
    protected function afterPeek(Model $record): void {}

    public function peek(int $id): void
    {
        $class = $this->peekModel();

        // Tenant-scoped: another hospital's id is a 404, never a silent read.
        $record = $class::query()->findOrFail($id);
        $this->authorizePeek($record);

        // getKey(), not ->id: this trait is handed a Model, and a model's key
        // is the one thing every model is guaranteed to have.
        $this->peekId = (int) $record->getKey();
        $this->forgetPeeked();
        $this->showPeek = true;

        $this->afterPeek($record);
    }

    public function closePeek(): void
    {
        $this->reset(['showPeek', 'peekId']);
        $this->forgetPeeked();
    }

    /** Re-read what the dialog is showing, after an action changed it. */
    public function refreshPeek(): void
    {
        $this->forgetPeeked();
    }

    protected function forgetPeeked(): void
    {
        unset($this->peeked);

        foreach ($this->peekCaches() as $cache) {
            unset($this->{$cache});
        }
    }

    #[Computed]
    public function peeked(): ?Model
    {
        if ($this->peekId === null) {
            return null;
        }

        $class = $this->peekModel();

        return $class::query()->with($this->peekRelations())->find($this->peekId);
    }
}
