<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Models\UserAbility;
use App\Services\AccessService;
use App\Support\Access\Abilities;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings, Staff and access: the clinic's roles against what each may do, in
 * plain words (docs/rbac.md). A tick changes it at once, for everybody in that
 * role, on the web and in the app alike. AccessService holds every rule; this
 * screen only shows the table and passes the clicks on.
 *
 * Anybody who runs the settings may look; only a hospital admin may change.
 */
#[Layout('layouts.admin')]
class Access extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
    }

    private function access(): AccessService
    {
        return app(AccessService::class);
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Auth::user();

        return $actor;
    }

    public function toggle(string $role, string $ability): void
    {
        abort_unless(AccessService::canManage($this->actor()), 403);

        $matrix = $this->access()->matrix();
        if (! isset($matrix[$role][$ability])) {
            $this->dispatch('toast', message: 'That role or ability is not one of the list.', type: 'error');

            return;
        }
        // A box that is partly on is switched OFF by a press, taking away the part the role has.
        $allowed = ! ($matrix[$role][$ability]['on'] || $matrix[$role][$ability]['partial']);

        try {
            $moved = $this->access()->setForRole($this->actor(), $role, $ability, $allowed);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $state = $allowed ? 'on' : 'off';
        $names = implode(', ', array_map(fn ($a) => Abilities::label($a), $moved));
        $message = "{$names}: {$state} for every ".$this->roleLabel($role).'.';
        $this->dispatch('toast', message: $message, type: 'success');
    }

    public function resetRole(string $role): void
    {
        abort_unless(AccessService::canManage($this->actor()), 403);

        try {
            $this->access()->resetRole($this->actor(), $role);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('toast', message: 'The '.$this->roleLabel($role).' role is back to the standard settings.', type: 'success');
    }

    private function roleLabel(string $role): string
    {
        return strtolower((new User(['role' => $role]))->role_label);
    }

    public function render(): View
    {
        // Each person's extra abilities, through the tenant scope; the people
        // come from this hospital's staff only (User carries no scope).
        $extras = UserAbility::query()->get(['user_id', 'ability'])->groupBy('user_id');
        $people = $extras->isEmpty() ? collect() : User::query()->currentHospital()
            ->whereKey($extras->keys()->all())->orderBy('name')->get(['id', 'name', 'role']);

        return view('livewire.settings.access', [
            'matrix' => $this->access()->matrix(),
            'roles' => array_combine(Abilities::ROLES, array_map(fn ($r) => (new User(['role' => $r]))->role_label, Abilities::ROLES)),
            'canEdit' => AccessService::canManage($this->actor()),
            'people' => $people,
            'extras' => $extras,
        ])->title('Staff and access');
    }
}
