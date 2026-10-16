<?php

namespace App\Livewire\Super\Errors;

use App\Livewire\Concerns\WithTable;
use App\Models\ErrorEvent;
use App\Models\Hospital;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What went wrong, across the platform: one row per kind of error, newest
 * first, with how often and the references people quote to support. Search
 * finds a reference. Resolving hides a row until the error happens again.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithTable;

    #[Url(history: true, except: 'open')]
    public string $state = 'open';

    #[Url(history: true, except: '')]
    public string $source = '';

    /** @return list<string> */
    protected function resetsPage(): array
    {
        return ['state', 'source'];
    }

    public function resolve(int $id): void
    {
        ErrorEvent::query()->whereKey($id)->update(['resolved_at' => now()]);
        $this->dispatch('toast', message: 'Marked as fixed. It comes back if it happens again.', type: 'success');
    }

    public function reopen(int $id): void
    {
        ErrorEvent::query()->whereKey($id)->update(['resolved_at' => null]);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $term = trim($this->search);

        $errors = ErrorEvent::query()
            ->when($this->state === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($this->state === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('last_ref', strtoupper($term))
                ->orWhere('refs', 'like', '%'.strtoupper($term).'%')
                ->orWhere('message', 'like', "%{$term}%")
                ->orWhere('class', 'like', "%{$term}%")
                ->orWhere('path', 'like', "%{$term}%")))
            ->orderByDesc('last_seen_at')
            ->paginate($this->perPage);

        $hospitals = Hospital::withoutGlobalScopes()->whereIn('id', $errors->pluck('last_hospital_id')->filter()->unique())->pluck('name', 'id');

        return view('livewire.super.errors.index', [
            'errors' => $errors,
            'hospitals' => $hospitals,
            'openCount' => ErrorEvent::query()->whereNull('resolved_at')->count(),
        ])->title('Errors');
    }
}
