<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Reusable data-table behaviour for Livewire index components: URL-bound
 * search / sort / per-page with pagination, themed pagination view, and a
 * generic "reset to page 1 when a filter changes" hook.
 *
 * Guarantees (docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md C3, C12, F1):
 *  - perPage is whitelisted (PER_PAGE_OPTIONS) — never an unbounded query;
 *  - search is capped at 100 chars;
 *  - sort columns are whitelisted by sortableFields(); sort params only
 *    appear in the URL for tables that actually sort;
 *  - every property listed in $resetsPage (plus search/perPage) resets the
 *    page, so a new filter can't strand the user on an empty page.
 */
trait WithTable
{
    use WithPagination;

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(history: true, except: '')]
    public string $sortField = '';

    #[Url(history: true, except: 'desc')]
    public string $sortDir = 'desc';

    #[Url(history: true, except: 20)]
    public int $perPage = 20;

    /** Component properties (filters) whose change resets pagination. @return list<string> */
    protected function resetsPage(): array
    {
        return [];
    }

    /** Livewire trait hook: fires before any public property is updated. */
    public function updatingWithTable(string $name, mixed $value): void
    {
        if (in_array($name, array_merge(['search', 'perPage'], $this->resetsPage()), true)) {
            $this->resetPage();
        }
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 100);
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 20;
        }
    }

    /** Called by Livewire after URL-bound properties are hydrated from the query string. */
    public function bootedWithTable(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 20;
        }
        if (mb_strlen($this->search) > 100) {
            $this->search = mb_substr($this->search, 0, 100);
        }
        if ($this->sortField !== '' && ! in_array($this->sortField, $this->sortableFields(), true)) {
            $this->sortField = '';
        }
        $this->sortDir = $this->sortDir === 'asc' ? 'asc' : 'desc';
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields(), true)) {
            return;
        }
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    /** Whitelist of orderable columns — override in the host component. @return list<string> */
    protected function sortableFields(): array
    {
        return [];
    }

    /** Apply the current sort to a query, guarded by the whitelist; falls back to $default. */
    protected function applySort(Builder $query, ?\Closure $default = null): Builder
    {
        if ($this->sortField !== '' && in_array($this->sortField, $this->sortableFields(), true)) {
            return $query->orderBy($this->sortField, $this->sortDir === 'asc' ? 'asc' : 'desc');
        }

        return $default ? $default($query) : $query->latest();
    }

    /** Themed, AJAX pagination for every table (config pagination_theme is bypassed). */
    public function paginationView(): string
    {
        return 'livewire.partials.pagination';
    }

    public function paginationSimpleView(): string
    {
        return 'livewire.partials.pagination';
    }

    /** Ready-made argument for ->links(): {{ $rows->links(data: $this->paginationData()) }} */
    public function paginationData(): array
    {
        return ['perPageOptions' => self::PER_PAGE_OPTIONS];
    }
}
