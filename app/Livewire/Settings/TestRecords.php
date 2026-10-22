<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithTable;
use App\Services\TestRecordsService;
use App\Support\Edition;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Settings, Remove test records (docs/test-records.md). Thin: it lists and
 * filters patients, keeps the ticks, and hands the chosen ones with the typed
 * hospital name to TestRecordsService, which owns every rule.
 */
#[Layout('layouts.admin')]
class TestRecords extends Component
{
    use WithTable;

    /** Created before this day. */
    #[Url(except: '')]
    public string $before = '';

    /** Only patients with no payment at all. */
    #[Url(except: false)]
    public bool $unpaid = false;

    /** @var list<int> ticked patient ids */
    public array $picked = [];

    public bool $confirming = false;

    public string $typedName = '';

    public function mount(TestRecordsService $records): void
    {
        $this->allowed($records);
    }

    private function allowed(TestRecordsService $records): void
    {
        abort_if($records->refusal(Auth::user()) !== null, 403);
    }

    protected function resetsPage(): array
    {
        return ['before', 'unpaid'];
    }

    /** Tick every patient the filters show on this page. */
    public function pickShown(TestRecordsService $records): void
    {
        $this->allowed($records);
        $shown = collect($this->rows($records)->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->picked = array_values(array_unique(array_merge(array_map('intval', $this->picked), $shown)));
    }

    public function clearPicked(): void
    {
        $this->picked = [];
    }

    public function confirm(TestRecordsService $records): void
    {
        $this->allowed($records);
        if ($this->picked === []) {
            $this->dispatch('toast', message: 'Tick the patients to remove first.', type: 'error');

            return;
        }
        $this->typedName = '';
        $this->resetErrorBag();
        $this->confirming = true;
    }

    public function closeConfirm(): void
    {
        $this->confirming = false;
        $this->typedName = '';
    }

    public function remove(TestRecordsService $records): void
    {
        $this->allowed($records);
        $this->resetErrorBag();

        try {
            $result = $records->remove(array_map('intval', $this->picked), $this->typedName, Auth::user());
        } catch (RuntimeException $e) {
            $this->addError('typedName', $e->getMessage());

            return;
        }

        $this->confirming = false;
        $this->typedName = '';
        $this->picked = [];
        $this->resetPage();

        $message = $result['removed'].' '.\Illuminate\Support\Str::plural('patient', $result['removed']).' removed.';
        if ($result['refused'] !== []) {
            $first = array_key_first($result['refused']);
            $message .= ' '.count($result['refused']).' kept: '.$first.', '.$result['refused'][$first];
        }
        $this->dispatch('toast', message: $message, type: $result['refused'] === [] ? 'success' : 'error');
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Patient> */
    private function rows(TestRecordsService $records): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $records->candidates(['before' => $this->before, 'name' => $this->search, 'unpaid' => $this->unpaid])
            ->paginate($this->perPage);
    }

    public function render(TestRecordsService $records): View
    {
        return view('livewire.settings.test-records', [
            'rows' => $this->rows($records),
            'hospitalName' => (string) $records->hospital()->name,
            'backsUp' => Edition::isStandalone(),
            'filtered' => $this->search !== '' || $this->before !== '' || $this->unpaid,
        ])->title('Remove test records');
    }
}
