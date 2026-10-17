<?php

namespace App\Livewire\Immunisations;

use App\Livewire\Concerns\WithTable;
use App\Services\ImmunisationService;
use App\Support\MotherChild\MotherChild;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Immunisations due (roadmap 04 §4): the children with a dose due or overdue
 * on a day, overdue first, for the immunisation clinic's morning and the
 * defaulter calls. ImmunisationService::dueList decides; this lists.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithTable;

    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: '')]
    public string $on = '';

    /** @return list<string> */
    protected function resetsPage(): array
    {
        return ['status', 'on'];
    }

    public function mount(): void
    {
        abort_unless(MotherChild::canView(auth()->user()), 403);
    }

    public function render(ImmunisationService $service)
    {
        abort_unless(MotherChild::canView(auth()->user()), 403);
        $status = in_array($this->status, ['due', 'overdue'], true) ? $this->status : '';
        try {
            $on = $this->on !== '' ? Carbon::parse($this->on)->startOfDay() : Carbon::today();
        } catch (\Throwable) {
            $on = Carbon::today();
        }

        $all = $service->dueList($on, $this->search, $status);
        $page = max(1, $this->getPage());
        $rows = new LengthAwarePaginator($all->forPage($page, $this->perPage)->values(), $all->count(), $this->perPage, $page, ['pageName' => 'page']);

        return view('livewire.immunisations.index', [
            'rows' => $rows,
            'onDate' => $on,
            'overdueTotal' => $all->sum('overdue'),
        ])->title('Immunisations due');
    }
}
