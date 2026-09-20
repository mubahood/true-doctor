<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\DashboardWidgets;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * ONE reusable dashboard widget host. Every section of every role cockpit is an
 * instance of this component: it is #[Lazy] (skeleton first, data after paint),
 * polls while the tab is visible, and reads its payload from DashboardWidgets —
 * i.e. always through the 45 s per-tenant/role/user cache, never straight from
 * DashboardService.
 *
 * `$widget` is #[Locked] AND re-validated against the viewer's own role view and
 * permissions on every request, so a forged widget name can neither escape the
 * role's cockpit nor bypass the @can that gated the old Blade partial.
 */
#[Lazy]
class Section extends Component
{
    #[Locked]
    public string $widget = '';

    public function mount(string $widget): void
    {
        $this->widget = $widget;
        $this->authorizeWidget();
    }

    private function authorizeWidget(): string
    {
        $user = Auth::user();
        $role = Index::roleViewFor($user);
        abort_unless(DashboardWidgets::allows($role, $this->widget, $user), 403);

        return $role;
    }

    public function render(DashboardWidgets $widgets)
    {
        $role = $this->authorizeWidget();

        return view('livewire.dashboard.section', [
            'partial' => 'livewire.dashboard.widgets.'.$this->widget,
            'data' => $widgets->data($this->widget, $role, Auth::user()),
        ]);
    }
}
