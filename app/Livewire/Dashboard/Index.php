<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\DashboardWidgets;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Role-based dashboard (Board shape). Replaces DashboardController: it picks the
 * same per-role cockpit from a constant map and renders it as a grid of
 * `Dashboard\Section` children — each one #[Lazy], polled and cache-backed —
 * plus the quick-actions grid, which is cheap and stays inline.
 *
 * The role view is resolved from a whitelist (ROLE_VIEWS + 'super'/'fallback'),
 * never from user input, so the include below can never become a view-path
 * injection (plan D10).
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    /** Same map as the retired DashboardController::ROLE_VIEWS. */
    private const ROLE_VIEWS = [
        'hospital_admin' => 'admin',
        'doctor' => 'doctor',
        'nurse' => 'nurse',
        'receptionist' => 'receptionist',
        'pharmacist' => 'pharmacist',
        'lab_technician' => 'lab',
        'radiologist' => 'radiology',
        'accountant' => 'accountant',
        'records_officer' => 'records',
    ];

    /** The whitelisted role-view key for the signed-in user. */
    public static function roleViewFor(?\App\Models\User $user): string
    {
        if ($user === null) {
            return 'fallback';
        }

        $view = $user->isSuperAdmin() ? 'super' : (self::ROLE_VIEWS[$user->role] ?? 'fallback');

        return array_key_exists($view, DashboardWidgets::WIDGETS) ? $view : 'fallback';
    }

    public function render()
    {
        $user = Auth::user();
        abort_unless($user !== null, 403);

        return view('livewire.dashboard.index', [
            'role' => self::roleViewFor($user),
        ])->title('Dashboard');
    }
}
