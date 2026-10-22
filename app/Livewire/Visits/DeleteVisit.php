<?php

namespace App\Livewire\Visits;

use App\Models\Visit;
use App\Services\VisitService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * "Delete visit": the dialog behind the visit page's button and the visits
 * list's row menu (docs/visits.md, "Deleting a visit"). Thin: it asks for a
 * reason, shows what will be undone, and calls VisitService::delete, which
 * owns the rules and refuses a visit somebody has paid on.
 *
 * Mount once per page and dispatch `visit-delete` with the visit id. On the
 * visit's own page (`leave` true) a deleted visit sends the reader back to
 * the list; on the list it simply drops out of it.
 */
class DeleteVisit extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $visitId = null;

    #[Locked]
    public bool $leave = false;

    public bool $show = false;

    public ?string $reason = null;

    public function mount(bool $leave = false): void
    {
        $this->leave = $leave;
    }

    #[On('visit-delete')]
    public function open(int $visitId): void
    {
        $visit = Visit::findOrFail($visitId);   // tenant-scoped: another hospital's id is a 404
        $this->authorize('delete', $visit);

        $this->visitId = $visit->id;
        $this->reason = null;
        $this->resetErrorBag();
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->reason = null;
    }

    public function delete(VisitService $visits): mixed
    {
        $visit = $this->visit();
        if ($visit === null) {
            return null;
        }
        $this->authorize('delete', $visit);

        $this->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:255']],
            ['reason.required' => 'Say why the visit is being deleted.', 'reason.min' => 'Say why the visit is being deleted.'],
        );

        try {
            $visits->delete($visit, Auth::id(), trim((string) $this->reason));
        } catch (RuntimeException $e) {
            $this->addError('reason', $e->getMessage());

            return null;
        }

        $number = $visit->visit_no;
        $this->close();

        if ($this->leave) {
            session()->flash('success', 'Visit '.$number.' deleted.');

            return $this->redirectRoute('admin.visits.index', navigate: true);
        }

        $this->dispatch('toast', message: 'Visit '.$number.' deleted.', type: 'success');
        $this->dispatch('visit-deleted');

        return null;
    }

    private function visit(): ?Visit
    {
        return $this->visitId === null ? null : Visit::query()->with(['patient', 'orders', 'invoices'])->find($this->visitId);
    }

    public function render()
    {
        $visit = $this->show ? $this->visit() : null;

        return view('livewire.visits.delete-visit', [
            'visit' => $visit,
            'blocker' => $visit !== null ? app(VisitService::class)->deleteBlocker($visit) : null,
        ]);
    }
}
